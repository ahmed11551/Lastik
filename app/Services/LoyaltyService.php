<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Services;

use Autometria\Enums\LoyaltyTierEnum;
use Autometria\Enums\LoyaltyTransactionTypeEnum;
use Autometria\Models\Customer;
use Autometria\Models\LoyaltyTransaction;
use Autometria\Support\AuditLog;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Block 4.3 — начисление / списание бонусов с anti-fraud лимитами.
 */
final class LoyaltyService
{
    public const MAX_SPEND_RATIO = 0.50;

    public function calculateEarnedBonus(Customer $customer, float $payableAmount): float
    {
        if ($payableAmount <= 0) {
            return 0.0;
        }

        $tier = LoyaltyTierEnum::tryFrom((string) ($customer->tier ?: 'BRONZE')) ?? LoyaltyTierEnum::BRONZE;

        return round($payableAmount * $tier->earnRate(), 2);
    }

    /**
     * Максимум списания: min(requested, balance, 50% чека).
     */
    public function applyBonusSpend(Customer $customer, float $requestedPoints, float $receiptTotal): float
    {
        if ($requestedPoints <= 0 || $receiptTotal <= 0) {
            return 0.0;
        }

        $cap = round($receiptTotal * self::MAX_SPEND_RATIO, 2);
        $balance = round((float) $customer->bonus_balance, 2);

        return round(min($requestedPoints, $balance, $cap), 2);
    }

    /**
     * Preview для POS: сколько можно списать / сколько начислят.
     *
     * @return array{
     *   tier: string,
     *   bonus_balance: float,
     *   max_spend: float,
     *   spend: float,
     *   earn: float,
     *   payable_after_spend: float,
     *   earn_rate: float
     * }
     */
    public function calculateForCart(Customer $customer, float $cartTotal, float $requestedSpend = 0.0): array
    {
        $tier = LoyaltyTierEnum::tryFrom((string) ($customer->tier ?: 'BRONZE')) ?? LoyaltyTierEnum::BRONZE;
        $spend = $this->applyBonusSpend($customer, $requestedSpend, $cartTotal);
        $payable = round(max(0, $cartTotal - $spend), 2);
        $earn = $this->calculateEarnedBonus($customer, $payable);

        return [
            'tier' => $tier->value,
            'bonus_balance' => round((float) $customer->bonus_balance, 2),
            'max_spend' => round(min((float) $customer->bonus_balance, $cartTotal * self::MAX_SPEND_RATIO), 2),
            'spend' => $spend,
            'earn' => $earn,
            'payable_after_spend' => $payable,
            'earn_rate' => $tier->earnRate(),
        ];
    }

    /**
     * Атомарное списание + начисление при закрытии чека (внутри DB-транзакции).
     *
     * @return array{spend: float, earn: float, balance: float, tier: string}
     */
    public function settleReceipt(
        int $tenantId,
        int $customerId,
        float $receiptTotal,
        float $requestedSpend = 0.0,
        ?int $receiptId = null,
        ?int $orderId = null,
        ?int $actorId = null,
    ): array {
        if ($receiptTotal <= 0) {
            throw new InvalidArgumentException('Receipt total must be positive');
        }

        return DB::transaction(function () use (
            $tenantId, $customerId, $receiptTotal, $requestedSpend, $receiptId, $orderId, $actorId
        ): array {
            $customer = Customer::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereKey($customerId)
                ->lockForUpdate()
                ->firstOrFail();

            // Idempotent: one settle per order (anti double-earn from PaymentService + ReceiptService).
            if ($orderId !== null) {
                $already = LoyaltyTransaction::query()->withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('order_id', $orderId)
                    ->whereIn('type', [
                        LoyaltyTransactionTypeEnum::EARN->value,
                        LoyaltyTransactionTypeEnum::SPEND->value,
                    ])
                    ->exists();
                if ($already) {
                    return [
                        'spend' => 0.0,
                        'earn' => 0.0,
                        'balance' => round((float) $customer->bonus_balance, 2),
                        'tier' => (string) ($customer->tier ?: LoyaltyTierEnum::BRONZE->value),
                    ];
                }
            }

            $spend = $this->applyBonusSpend($customer, $requestedSpend, $receiptTotal);
            $balance = round((float) $customer->bonus_balance, 2);

            if ($spend > 0) {
                $balance = round($balance - $spend, 2);
                if ($balance < -0.001) {
                    throw new InvalidArgumentException('Insufficient bonus balance');
                }
                LoyaltyTransaction::query()->withoutGlobalScopes()->forceCreate([
                    'tenant_id' => $tenantId,
                    'customer_id' => $customerId,
                    'receipt_id' => $receiptId,
                    'order_id' => $orderId,
                    'type' => LoyaltyTransactionTypeEnum::SPEND->value,
                    'amount' => -$spend,
                    'balance_after' => $balance,
                    'meta' => 'receipt_spend',
                ]);
            }

            $payable = round(max(0, $receiptTotal - $spend), 2);
            $earn = $this->calculateEarnedBonus($customer, $payable);

            if ($earn > 0) {
                $balance = round($balance + $earn, 2);
                LoyaltyTransaction::query()->withoutGlobalScopes()->forceCreate([
                    'tenant_id' => $tenantId,
                    'customer_id' => $customerId,
                    'receipt_id' => $receiptId,
                    'order_id' => $orderId,
                    'type' => LoyaltyTransactionTypeEnum::EARN->value,
                    'amount' => $earn,
                    'balance_after' => $balance,
                    'meta' => 'receipt_earn',
                ]);
            }

            $totalSpent = round((float) $customer->total_spent + $receiptTotal, 2);
            $tier = LoyaltyTierEnum::fromTotalSpent($totalSpent);

            $customer->forceFill([
                'bonus_balance' => $balance,
                'total_spent' => $totalSpent,
                'tier' => $tier->value,
            ])->save();

            AuditLog::write(
                $tenantId,
                $actorId ?? auth()->id(),
                'loyalty.receipt_settled',
                Customer::class,
                $customerId,
                [],
                [
                    'spend' => $spend,
                    'earn' => $earn,
                    'balance' => $balance,
                    'tier' => $tier->value,
                    'order_id' => $orderId,
                    'receipt_id' => $receiptId,
                ],
            );

            return [
                'spend' => $spend,
                'earn' => $earn,
                'balance' => $balance,
                'tier' => $tier->value,
            ];
        });
    }
}
