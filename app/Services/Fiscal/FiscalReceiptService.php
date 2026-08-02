<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @package    Autometria\Services\Fiscal
 * @copyright  (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author     Себиев Ахмед Сулейманович (Chief Software Architect / Lead Developer)
 * @license    Proprietary & Confidential.
 */

declare(strict_types=1);

namespace Autometria\Services\Fiscal;

use Autometria\Enums\FiscalReceiptStatus;
use Autometria\Enums\FiscalReceiptType;
use Autometria\Exceptions\Domain\FiscalizationValidationException;
use Autometria\Jobs\FiscalizeReceiptJob;
use Autometria\Models\FiscalReceipt;
use Autometria\Models\Order;
use Autometria\Models\Payment;
use Illuminate\Support\Str;

/**
 * Создаёт фискальный чек (со снимком позиций/НДС и валидацией сходимости копеек)
 * и ставит в очередь его пробитие.
 */
final class FiscalReceiptService
{
    public function __construct(
        private readonly ?FiscalDriverInterface $driver = null,
        private readonly FiscalDiscountService $discounts = new FiscalDiscountService,
    ) {}

    /**
     * Снимок позиций заказа + распределение скидок (тег 1079), с жёстким assert.
     *
     * @return array{items: list<array>, total: int, payment_address: string}
     */
    public function buildSaleSnapshot(Order $order, ?float $receiptTotal = null): array
    {
        $rawItems = [];
        $orderItems = $order->orderItems->values();
        foreach ($orderItems as $item) {
            /** @var \Autometria\Models\OrderItem $item */
            $snapshot = $item->snapshot ?? [];
            $row = [
                'name' => $snapshot['name'] ?? ($item->product?->name ?? ('Позиция #' . $item->id)),
                'price' => (float) $item->price,
                'quantity' => (float) ($item->qty ?? 1),
                'vat_rate' => $item->vat_rate ?? 'none',
            ];

            // ФФД 1.2: тег 1162 (код товара / КИЗ) + 1163 (мера количества) для маркировки.
            $cis = trim((string) ($item->marking_code ?? ''));
            if ($cis !== '') {
                $gtin = (string) ($item->gtin ?? '');
                $serial = (string) ($item->serial_number ?? '');
                $row['marking_code'] = $cis;
                $row['gtin'] = $gtin;
                $row['serial_number'] = $serial;
                $row['fiscal_tags'] = [
                    '1162' => $this->buildTag1162($gtin, $serial),
                    '1163' => '0', // штуки
                ];
                $row['product_code'] = [
                    'gs1m' => bin2hex($this->buildGs1Binary($gtin, $serial)),
                ];
            }

            $rawItems[] = $row;
        }

        $targetTotal = $receiptTotal ?? (float) $order->total;
        // При построении снимка из заказа валидируем сходимость позиций (line_total),
        // но НЕ платежей (частичная оплата легитимна — чек пробивается на сумму платежа).
        // Валидация сходимости платежей доступна отдельно через FiscalDiscountService::allocate().
        $payments = [];

        // Валидация сходимости копеек (бросит FiscalizationValidationException до ККТ).
        $allocated = $this->discounts->allocate($rawItems, $targetTotal, $payments);

        // Preserve marking / fiscal tags lost during discount allocation.
        foreach ($allocated['items'] as $i => &$allocatedItem) {
            foreach (['marking_code', 'gtin', 'serial_number', 'fiscal_tags', 'product_code'] as $key) {
                if (isset($rawItems[$i][$key])) {
                    $allocatedItem[$key] = $rawItems[$i][$key];
                }
            }
        }
        unset($allocatedItem);

        return [
            'items' => $allocated['items'],
            'total' => $allocated['total'],
            'payment_address' => $order->payload['payment_address'] ?? 'https://autometria.local',
        ];
    }

    /**
     * Тег 1162 — hex representation of GS1 product code for ФФД.
     */
    private function buildTag1162(string $gtin, string $serial): string
    {
        return strtoupper(bin2hex($this->buildGs1Binary($gtin, $serial)));
    }

    private function buildGs1Binary(string $gtin, string $serial): string
    {
        $gtin = preg_replace('/\D/', '', $gtin) ?: '00000000000000';
        $gtin = str_pad(substr($gtin, -14), 14, '0', STR_PAD_LEFT);

        return '01'.$gtin.'21'.$serial;
    }

    public function createSaleReceipt(
        int $tenantId,
        ?int $cashShiftId,
        ?int $orderId,
        ?int $paymentId,
        ?string $idempotencyKey = null,
    ): FiscalReceipt {
        $order = $orderId !== null ? Order::query()->withoutGlobalScopes()->findOrFail($orderId) : null;
        $payment = $paymentId !== null ? Payment::query()->withoutGlobalScopes()->findOrFail($paymentId) : null;

        // Чек фискализируется на сумму ПЛАТЕЖА (не весь заказ — возможна частичная оплата).
        $receiptTotal = $payment !== null ? (float) $payment->amount : ($order !== null ? (float) $order->total : 0.0);

        // Валидация сходимости копеек ДО создания записи (если заказ задан).
        $snapshot = $order !== null
            ? $this->buildSaleSnapshot($order, $receiptTotal)
            : ['items' => [], 'total' => (int) round($receiptTotal * 100), 'payment_address' => 'https://autometria.local'];

        $totalAmount = $receiptTotal;

        $receipt = FiscalReceipt::query()->withoutGlobalScopes()->forceCreate([
            'tenant_id' => $tenantId,
            'cash_shift_id' => $cashShiftId,
            'order_id' => $orderId,
            'payment_id' => $paymentId,
            'operation' => FiscalReceiptType::SELL->value,
            'status' => FiscalReceiptStatus::PENDING->value,
            'idempotency_key' => $idempotencyKey ?? (string) Str::uuid(),
            'driver_request_id' => (string) Str::uuid(),
            'total_amount' => $totalAmount,
            'payload_snapshot' => $snapshot,
        ]);

        // Гарантированная фискализация: dispatchSync — выполняется немедленно
        // в рамках текущего процесса (Queue=sync). При реальном worker — dispatch().
        FiscalizeReceiptJob::dispatchSync($receipt->id);

        return $receipt;
    }

    /**
     * Чек возврата прихода (54-ФЗ / ФФД operation = sell_refund).
     */
    public function createRefundReceipt(
        int $tenantId,
        ?int $cashShiftId,
        int $orderId,
        ?int $paymentId,
        float $refundTotal,
        array $itemsSnapshot,
        ?string $idempotencyKey = null,
    ): FiscalReceipt {
        $snapshot = [
            'items' => $itemsSnapshot,
            'total' => (int) round($refundTotal * 100),
            'payment_address' => 'https://autometria.local',
            'operation' => FiscalReceiptType::SELL_REFUND->value,
        ];

        $receipt = FiscalReceipt::query()->withoutGlobalScopes()->forceCreate([
            'tenant_id' => $tenantId,
            'cash_shift_id' => $cashShiftId,
            'order_id' => $orderId,
            // Unique (tenant_id, payment_id) — refund чек не делит payment_id с sell-чеком.
            'payment_id' => null,
            'operation' => FiscalReceiptType::SELL_REFUND->value,
            'status' => FiscalReceiptStatus::PENDING->value,
            'idempotency_key' => $idempotencyKey ?? (string) Str::uuid(),
            'driver_request_id' => (string) Str::uuid(),
            'total_amount' => $refundTotal,
            'payload_snapshot' => array_merge($snapshot, [
                'source_payment_id' => $paymentId,
            ]),
        ]);

        FiscalizeReceiptJob::dispatchSync($receipt->id);

        return $receipt->fresh();
    }

    public function driver(): FiscalDriverInterface
    {
        if ($this->driver !== null) {
            return $this->driver;
        }

        if (app()->environment('production') && config('services.atol.token')) {
            return new AtolOnlineDriver(
                (string) config('services.atol.group'),
                (string) config('services.atol.token'),
                (string) config('services.atol.inn'),
            );
        }

        return new NullFiscalDriver();
    }
}
