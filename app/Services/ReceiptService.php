<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Services;

use Autometria\Models\Customer;
use Autometria\Models\Order;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Закрытие чека с атомарным loyalty settle (Block 4.3).
 */
final class ReceiptService
{
    public function __construct(
        private readonly LoyaltyService $loyalty,
    ) {}

    /**
     * Проведение лояльности для чека: customer_id + bonus_spend.
     * receiptBase = subtotal − discount (если не передан — order.total).
     *
     * @return array{spend: float, earn: float, balance: float, tier: string, cash_due: float}|null
     */
    public function create(
        int $tenantId,
        Order $order,
        ?int $customerId = null,
        float $bonusSpend = 0.0,
        ?float $receiptBase = null,
        ?int $receiptId = null,
        ?int $actorId = null,
    ): ?array {
        $customerId = $customerId ?? ($order->customer_id ? (int) $order->customer_id : null);
        if ($customerId === null) {
            return null;
        }

        if ($order->customer_id === null) {
            $order->forceFill(['customer_id' => $customerId])->save();
        } elseif ((int) $order->customer_id !== $customerId) {
            throw new InvalidArgumentException('customer_id mismatch with order');
        }

        // Anti-fraud: base = subtotal - discount; never use post-bonus payable.
        $base = $receiptBase !== null ? round($receiptBase, 2) : round((float) $order->total, 2);
        if ($base <= 0) {
            throw new InvalidArgumentException('Receipt base must be positive');
        }

        return DB::transaction(function () use (
            $tenantId, $order, $customerId, $bonusSpend, $base, $receiptId, $actorId
        ): array {
            $settled = $this->loyalty->settleReceipt(
                $tenantId,
                $customerId,
                $base,
                $bonusSpend,
                $receiptId,
                (int) $order->id,
                $actorId,
            );

            $settled['cash_due'] = round(max(0, $base - $settled['spend']), 2);

            return $settled;
        });
    }

    /**
     * @return array{spend: float, earn: float, balance: float, tier: string}|null
     */
    public function settleLoyaltyForOrder(
        int $tenantId,
        Order $order,
        float $requestedBonusSpend = 0.0,
        ?int $receiptId = null,
        ?int $actorId = null,
    ): ?array {
        $result = $this->create(
            $tenantId,
            $order,
            $order->customer_id ? (int) $order->customer_id : null,
            $requestedBonusSpend,
            (float) $order->total,
            $receiptId,
            $actorId,
        );

        if ($result === null) {
            return null;
        }

        unset($result['cash_due']);

        return $result;
    }

    /**
     * Серверный cap списания для POS до accept().
     */
    public function previewSpend(int $tenantId, int $customerId, float $receiptBase, float $requested): float
    {
        $customer = Customer::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereKey($customerId)
            ->firstOrFail();

        return $this->loyalty->applyBonusSpend($customer, $requested, $receiptBase);
    }
}
