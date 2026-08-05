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
use Autometria\Services\Traits\BcMathDecimal;
use Autometria\Support\AuditLog;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Block 4.3 — начисление / списание бонусов с anti-fraud лимитами.
 */
final class LoyaltyService
{
    use BcMathDecimal;

    public const MAX_SPEND_RATIO = '0.50';

    public function calculateEarnedBonus(Customer $customer, float|int|string $payableAmount): float
    {
        if ($this->bcComp($payableAmount, '0') <= 0) {
            return 0.0;
        }

        $tier = LoyaltyTierEnum::tryFrom((string) ($customer->tier ?: 'BRONZE')) ?? LoyaltyTierEnum::BRONZE;

        return $this->bcToFloat(
            $this->bcRound($this->bcMul($payableAmount, (string) $tier->earnRate()), 2)
        );
    }

    /**
     * Максимум списания: min(requested, balance, 50% чека).
     */
    public function applyBonusSpend(Customer $customer, float|int|string $requestedPoints, float|int|string $receiptTotal): float
    {
        if ($this->bcComp($requestedPoints, '0') <= 0 || $this->bcComp($receiptTotal, '0') <= 0) {
            return 0.0;
        }

        $cap = $this->bcRound($this->bcMul($receiptTotal, self::MAX_SPEND_RATIO), 2);
        $balance = $this->bcRound($customer->bonus_balance ?? '0', 2);
        $requested = $this->bcRound($requestedPoints, 2);

        return $this->bcToFloat($this->bcMin($this->bcMin($requested, $balance), $cap));
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
        $payable = $this->bcRound($this->bcMax($this->bcSub($cartTotal, $spend), '0'), 2);
        $earn = $this->calculateEarnedBonus($customer, $payable);
        $balance = $this->bcRound($customer->bonus_balance ?? '0', 2);
        $maxSpend = $this->bcMin($balance, $this->bcMul($cartTotal, self::MAX_SPEND_RATIO));

        return [
            'tier' => $tier->value,
            'bonus_balance' => $this->bcToFloat($balance),
            'max_spend' => $this->bcToFloat($this->bcRound($maxSpend, 2)),
            'spend' => $spend,
            'earn' => $earn,
            'payable_after_spend' => $this->bcToFloat($payable),
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
        if ($this->bcComp($receiptTotal, '0') <= 0) {
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
                        'balance' => $this->bcToFloat($this->bcRound($customer->bonus_balance ?? '0', 2)),
                        'tier' => (string) ($customer->tier ?: LoyaltyTierEnum::BRONZE->value),
                    ];
                }
            }

            $spend = $this->applyBonusSpend($customer, $requestedSpend, $receiptTotal);
            $balance = $this->bcRound($customer->bonus_balance ?? '0', 2);

            if ($this->bcComp($spend, '0') > 0) {
                $balance = $this->bcRound($this->bcSub($balance, $spend), 2);
                if ($this->bcComp($balance, '-0.001') < 0) {
                    throw new InvalidArgumentException('Insufficient bonus balance');
                }
                LoyaltyTransaction::query()->withoutGlobalScopes()->forceCreate([
                    'tenant_id' => $tenantId,
                    'customer_id' => $customerId,
                    'receipt_id' => $receiptId,
                    'order_id' => $orderId,
                    'type' => LoyaltyTransactionTypeEnum::SPEND->value,
                    'amount' => $this->bcToFloat($this->bcSub('0', $spend)),
                    'balance_after' => $this->bcToFloat($balance),
                    'meta' => 'receipt_spend',
                ]);
            }

            $payable = $this->bcRound($this->bcMax($this->bcSub($receiptTotal, $spend), '0'), 2);
            $earn = $this->calculateEarnedBonus($customer, $payable);

            if ($this->bcComp($earn, '0') > 0) {
                $balance = $this->bcRound($this->bcAdd($balance, $earn), 2);
                LoyaltyTransaction::query()->withoutGlobalScopes()->forceCreate([
                    'tenant_id' => $tenantId,
                    'customer_id' => $customerId,
                    'receipt_id' => $receiptId,
                    'order_id' => $orderId,
                    'type' => LoyaltyTransactionTypeEnum::EARN->value,
                    'amount' => $earn,
                    'balance_after' => $this->bcToFloat($balance),
                    'meta' => 'receipt_earn',
                ]);
            }

            $totalSpent = $this->bcRound($this->bcAdd($customer->total_spent ?? '0', $receiptTotal), 2);
            $tier = LoyaltyTierEnum::fromTotalSpent($this->bcToFloat($totalSpent));

            $customer->forceFill([
                'bonus_balance' => $this->bcToFloat($balance),
                'total_spent' => $this->bcToFloat($totalSpent),
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
                    'balance' => $this->bcToFloat($balance),
                    'tier' => $tier->value,
                    'order_id' => $orderId,
                    'receipt_id' => $receiptId,
                ],
            );

            return [
                'spend' => $spend,
                'earn' => $earn,
                'balance' => $this->bcToFloat($balance),
                'tier' => $tier->value,
            ];
        });
    }
}
