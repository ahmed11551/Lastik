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
use Autometria\Jobs\FiscalizeReceiptJob;
use Autometria\Models\FiscalReceipt;
use Autometria\Models\Order;
use Autometria\Models\Payment;

/**
 * Создаёт фискальный чек и ставит в очередь его пробитие.
 *
 * Идемпотентность гарантируется уникальным idempotency_key (совпадает с
 * ключом чека), а повторный вызов FiscalizeReceiptJob пропускает уже
 * FISCALIZED записи.
 */
final class FiscalReceiptService
{
    /**
     * @param  FiscalDriverInterface|null  $driver  Inject a driver (e.g. a mock in tests).
     *                                            When null, driver() resolves Null/Atol by env.
     */
    public function __construct(
        private readonly ?FiscalDriverInterface $driver = null,
    ) {}

    /**
     * Построить payload чека продажи из заказа (позиции + НДС + сумма).
     *
     * @return array<string, mixed>
     */
    public function buildSalePayload(Order $order): array
    {
        $items = [];
        $total = 0.0;

        foreach ($order->orderItems as $item) {
            /** @var \Autometria\Models\OrderItem $item */
            $price = (float) $item->price;
            $qty = (float) ($item->qty ?? 1);
            $vat = $item->vat_rate ?? 'none';
            $sum = round($price * $qty, 2);
            $total += $sum;

            $snapshot = $item->snapshot ?? [];
            $name = $snapshot['name'] ?? ($item->product?->name ?? ('Позиция #' . $item->id));

            $items[] = [
                'name' => $name,
                'price' => $price,
                'quantity' => $qty,
                'sum' => $sum,
                'vat_rate' => $vat,
            ];
        }

        return [
            'items' => $items,
            'total' => round($total, 2),
            'payment_address' => $order->payload['payment_address'] ?? 'https://autometria.local',
        ];
    }

    public function createSaleReceipt(
        int $tenantId,
        ?int $cashShiftId,
        ?int $orderId,
        ?int $paymentId,
        ?string $idempotencyKey = null,
    ): FiscalReceipt {
        $order = $orderId !== null ? Order::query()->withoutGlobalScopes()->findOrFail($orderId) : null;
        $payload = $order !== null ? $this->buildSalePayload($order) : ['items' => [], 'total' => 0];

        $receipt = FiscalReceipt::query()->withoutGlobalScopes()->forceCreate([
            'tenant_id' => $tenantId,
            'cash_shift_id' => $cashShiftId,
            'order_id' => $orderId,
            'payment_id' => $paymentId,
            'type' => FiscalReceiptType::SELL->value,
            'status' => FiscalReceiptStatus::PENDING->value,
            'idempotency_key' => $idempotencyKey ?? (string) \Illuminate\Support\Str::uuid(),
            'payload' => $payload,
        ]);

        // Гарантированная фискализация: ставим задачу в очередь (dispatchSync —
        // выполняется немедленно в рамках текущего процесса, не зависит от
        // настроек очереди; при реальном worker можно заменить на dispatch()).
        FiscalizeReceiptJob::dispatchSync($receipt->id);

        return $receipt;
    }

    /**
     * Фабрика драйвера: в тестах/dev — NullFiscalDriver, в prod — AtolOnlineDriver.
     */
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
