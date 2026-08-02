<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @package    Autometria\Http\Controllers
 * @copyright  (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author     Себиев Ахмед Сулейманович
 * @license    Proprietary & Confidential.
 */

declare(strict_types=1);

namespace Autometria\Http\Controllers;

use Autometria\Enums\FiscalReceiptStatus;
use Autometria\Enums\FiscalReceiptType;
use Autometria\Jobs\FiscalizeReceiptJob;
use Autometria\Models\FiscalReceipt;
use Autometria\Models\Order;
use Autometria\Services\Fiscal\FiscalReceiptService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class FiscalReceiptController extends Controller
{
    public function __construct(
        private readonly FiscalReceiptService $fiscal,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_id' => ['nullable', 'integer', 'exists:orders,id'],
            'cash_shift_id' => ['nullable', 'integer', 'exists:cash_shifts,id'],
            'status' => ['nullable', 'string', 'in:pending,fiscalized,failed,refunded'],
        ]);

        $tenantId = (int) ($request->user()?->tenant_id ?? tenant_id() ?? 0);
        abort_unless($tenantId > 0, 422, 'Tenant context required');

        $q = FiscalReceipt::query()
            ->where('tenant_id', $tenantId)
            ->orderByDesc('id');

        if (! empty($data['order_id'])) {
            $q->where('order_id', (int) $data['order_id']);
        }
        if (! empty($data['cash_shift_id'])) {
            $q->where('cash_shift_id', (int) $data['cash_shift_id']);
        }
        if (! empty($data['status'])) {
            $q->where('status', $data['status']);
        }

        $rows = $q->limit(100)->get()->map(fn (FiscalReceipt $r) => $this->serialize($r))->values();

        return response()->json(['data' => $rows]);
    }

    public function show(Request $request, FiscalReceipt $fiscalReceipt): JsonResponse
    {
        $this->assertTenant($request, $fiscalReceipt);

        return response()->json(['data' => $this->serialize($fiscalReceipt)]);
    }

    /**
     * Create or enrich a fiscal receipt for an order (buyer contact / СНО / НДС).
     * If PaymentService already created a receipt, merges 54-ФЗ fields into payload.
     */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'order_id' => ['required', 'integer', 'exists:orders,id'],
            'payment_id' => ['nullable', 'integer', 'exists:payments,id'],
            'cash_shift_id' => ['nullable', 'integer', 'exists:cash_shifts,id'],
            'type' => ['nullable', 'string', 'in:sell,sell_refund,buy,buy_refund'],
            'electronic' => ['required', 'boolean'],
            'buyer_email' => ['nullable', 'email', 'max:255'],
            'buyer_phone' => ['nullable', 'string', 'max:32'],
            'tax_system' => ['required', 'string', 'in:osn,usn_income,usn_income_outcome,esn,patent'],
            'vat_rate' => ['required', 'string', 'in:20,10,0,none'],
            'items' => ['nullable', 'array'],
            'items.*.name' => ['required_with:items', 'string', 'max:255'],
            'items.*.qty' => ['nullable', 'numeric', 'min:0'],
            'items.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'items.*.price' => ['nullable', 'numeric', 'min:0'],
            'items.*.sum' => ['nullable', 'numeric', 'min:0'],
            'items.*.vat_rate' => ['nullable', 'string', 'in:20,10,0,none'],
            'idempotency_key' => ['nullable', 'string', 'max:100'],
        ]);

        $user = $request->user();
        abort_unless($user !== null, 401);
        $tenantId = (int) ($user->tenant_id ?? tenant_id() ?? 0);
        abort_unless($tenantId > 0, 422, 'Tenant context required');

        $orderId = (int) $data['order_id'];
        $order = Order::query()->where('tenant_id', $tenantId)->whereKey($orderId)->firstOrFail();

        $existing = FiscalReceipt::query()
            ->where('tenant_id', $tenantId)
            ->where('order_id', $orderId)
            ->orderByDesc('id')
            ->first();

        if ($existing !== null) {
            $existing->payload = $this->mergePayload($existing->payload ?? [], $data, $user->name ?? 'Кассир');
            $existing->save();

            return response()->json(['data' => $this->serialize($existing->fresh())], 200);
        }

        $receipt = $this->fiscal->createSaleReceipt(
            $tenantId,
            isset($data['cash_shift_id']) ? (int) $data['cash_shift_id'] : null,
            $orderId,
            isset($data['payment_id']) ? (int) $data['payment_id'] : null,
            $data['idempotency_key'] ?? ('manual-'.$orderId.'-'.Str::uuid()),
        );

        $receipt->type = FiscalReceiptType::tryFrom((string) ($data['type'] ?? 'sell')) ?? FiscalReceiptType::SELL;
        $receipt->payload = $this->mergePayload($receipt->payload ?? [], $data, $user->name ?? 'Кассир');
        $receipt->save();

        return response()->json(['data' => $this->serialize($receipt->fresh())], 201);
    }

    public function retry(Request $request, FiscalReceipt $fiscalReceipt): JsonResponse
    {
        $this->assertTenant($request, $fiscalReceipt);

        $status = $fiscalReceipt->status instanceof FiscalReceiptStatus
            ? $fiscalReceipt->status
            : FiscalReceiptStatus::tryFrom((string) $fiscalReceipt->status);

        abort_unless($status === FiscalReceiptStatus::FAILED, 422, 'Повтор возможен только для FAILED');

        $fiscalReceipt->status = FiscalReceiptStatus::PENDING;
        $fiscalReceipt->error_message = null;
        $fiscalReceipt->save();

        FiscalizeReceiptJob::dispatch($fiscalReceipt->id);

        return response()->json(['data' => $this->serialize($fiscalReceipt->fresh())]);
    }

    private function assertTenant(Request $request, FiscalReceipt $receipt): void
    {
        $tenantId = (int) ($request->user()?->tenant_id ?? tenant_id() ?? 0);
        abort_unless($tenantId > 0 && (int) $receipt->tenant_id === $tenantId, 403);
    }

    /**
     * @param  array<string, mixed>  $base
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function mergePayload(array $base, array $data, string $cashierName): array
    {
        $items = $base['items'] ?? [];
        if (! empty($data['items']) && is_array($data['items'])) {
            $items = [];
            foreach ($data['items'] as $row) {
                $qty = (float) ($row['quantity'] ?? $row['qty'] ?? 0);
                $sum = isset($row['sum']) ? (float) $row['sum'] : round(((float) ($row['price'] ?? 0)) * $qty, 2);
                $items[] = [
                    'name' => (string) $row['name'],
                    'price' => (float) ($row['price'] ?? 0),
                    'quantity' => $qty,
                    'qty' => $qty,
                    'sum' => $sum,
                    'vat_rate' => (string) ($row['vat_rate'] ?? $data['vat_rate'] ?? 'none'),
                ];
            }
        } elseif (! empty($data['vat_rate'])) {
            $items = array_map(static function (array $it) use ($data): array {
                if (($it['vat_rate'] ?? 'none') === 'none') {
                    $it['vat_rate'] = $data['vat_rate'];
                }
                return $it;
            }, $items);
        }

        $total = (float) ($base['total'] ?? 0);
        if ($items !== []) {
            $total = round(array_sum(array_map(static fn (array $i): float => (float) ($i['sum'] ?? 0), $items)), 2);
        }

        return array_merge($base, [
            'items' => $items,
            'total' => $total,
            'electronic' => (bool) $data['electronic'],
            'buyer_email' => $data['buyer_email'] ?? null,
            'buyer_phone' => $data['buyer_phone'] ?? null,
            'tax_system' => $data['tax_system'],
            'vat_rate' => $data['vat_rate'],
            'cashier_name' => $base['cashier_name'] ?? $cashierName,
            'organization_name' => $base['organization_name'] ?? 'AUTOMETRIA',
            'shift_number' => $base['shift_number'] ?? ($data['cash_shift_id'] ?? null),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(FiscalReceipt $r): array
    {
        return [
            'id' => $r->id,
            'tenant_id' => $r->tenant_id,
            'cash_shift_id' => $r->cash_shift_id,
            'order_id' => $r->order_id,
            'payment_id' => $r->payment_id,
            'type' => $r->type instanceof FiscalReceiptType ? $r->type->value : (string) $r->type,
            'status' => $r->status instanceof FiscalReceiptStatus ? $r->status->value : (string) $r->status,
            'idempotency_key' => $r->idempotency_key,
            'fiscal_document_number' => $r->fiscal_document_number,
            'fiscal_storage_number' => $r->fiscal_storage_number,
            'fiscal_sign' => $r->fiscal_sign,
            'qr_code_url' => $r->qr_code_url,
            'payload' => $r->payload,
            'error_message' => $r->error_message,
            'attempts' => $r->attempts,
            'fiscalized_at' => optional($r->fiscalized_at)?->toIso8601String(),
            'created_at' => optional($r->created_at)?->toIso8601String(),
            'updated_at' => optional($r->updated_at)?->toIso8601String(),
        ];
    }
}
