<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @package    Autometria\Core
 * @copyright  (c) 2026 Себиев Ахмед Сулейманович (Sebiev Akhmed Suleymanovich). All Rights Reserved.
 * @author     Себиев Ахмед Сулейманович (Chief Software Architect / Lead Developer)
 * @license    Proprietary & Confidential. Unauthorized copying, distribution,
 *             modification, or reverse engineering of this file, via any medium,
 *             is strictly prohibited.
 *
 * NOTICE: All information contained herein is, and remains the property of
 * Себиев Ахмед Сулейманович. The intellectual and technical concepts contained
 * herein are proprietary and protected by trade secret and copyright law.
 */
/**
 * LASTIK B2B SaaS Engine Core
 *
 * @copyright  (c) 2026 Себиев Ахмед Сулейманович (Sebiev Akhmed Suleymanovich). All Rights Reserved.
 * @author     Себиев Ахмед Сулейманович (Chief Software Architect / Lead Developer)
 * @license    Proprietary & Confidential. Unauthorized copying, distribution,
 *             modification, or reverse engineering of this file, via any medium,
 *             is strictly prohibited.
 *
 * NOTICE: All information contained herein is, and remains the property of
 * Себиев Ахмед Сулейманович. The intellectual and technical concepts contained
 * herein are proprietary and protected by trade secret and copyright law.
 */
/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 */

declare(strict_types=1);

namespace Autometria\Services;

use Autometria\Exceptions\Domain\NoActiveShiftException;
use Autometria\Exceptions\Domain\OverpaymentException;
use Autometria\Exceptions\Domain\ShiftAlreadyClosedException;
use Autometria\Models\CashShift;
use Autometria\Models\Dictionary;
use Autometria\Models\Order;
use Autometria\Models\Payment;
use Autometria\Models\PaymentCorrection;
use Autometria\Services\Fiscal\FiscalReceiptService;
use Autometria\Services\ReceiptService;
use Autometria\Support\AuditLog;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final class PaymentService
{
    public function __construct(
        private readonly DictionaryService $dictionaries = new DictionaryService,
    ) {}

    /**
     * @param  list<array{method: string, amount: float|int|string, payee_id?: int|null}>  $parts
     * @return list<Payment>
     */
    public function accept(
        int $tenantId,
        int $orderId,
        array $parts,
        int $createdBy,
        ?int $shiftId = null,
        float $bonusToSpend = 0.0,
        float $loyaltyCredit = 0.0,
    ): array {
        $payments = DB::transaction(function () use ($tenantId, $orderId, $parts, $createdBy, $shiftId, $bonusToSpend, $loyaltyCredit): array {
            set_current_tenant_id($tenantId);

            $order = Order::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereKey($orderId)
                ->lockForUpdate()
                ->firstOrFail();

            $shift = $this->resolveOpenShift($tenantId, $shiftId ?? $order->shift_id, $order->location_id);

            if ($shift->closed_at !== null || $shift->status === 'closed') {
                throw new RuntimeException('Shift is closed, use payment correction workflow');
            }

            $created = [];
            $orderTotal = (float) $order->total;
            $paidSum = 0.0;

            foreach ($parts as $part) {
                $amount = round((float) $part['amount'], 2);
                if ($amount <= 0) {
                    throw new \InvalidArgumentException('Payment amount must be positive');
                }

                $method = (string) $part['method'];

                // P1: payment method MUST be an active dictionary code. No fallback
                // to hardcoded strings — if no payment-form dictionary exists for the
                // tenant, payment is rejected (fail-closed, not fail-open).
                $this->dictionaries->assertActiveCode($tenantId, Dictionary::TYPE_PAYMENT_FORM, $method);

                $payment = Payment::query()->withoutGlobalScopes()->forceCreate([
                    'tenant_id' => $tenantId,
                    'order_id' => $order->id,
                    'shift_id' => $shift->id,
                    'method' => $method,
                    'type' => count($parts) > 1 ? 'mixed' : 'single',
                    'amount' => $amount,
                    'status' => 'paid',
                    'payee_id' => $part['payee_id'] ?? $createdBy,
                    'created_by' => $createdBy,
                ]);

                AuditLog::write(
                    $tenantId,
                    $createdBy,
                    'payment.created',
                    Payment::class,
                    (int) $payment->id,
                    [],
                    [
                        'method' => $method,
                        'amount' => $amount,
                        'order_id' => $order->id,
                        'payee_id' => $payment->payee_id,
                    ],
                    ['location_id' => $order->location_id, 'shift_id' => $shift->id],
                );

                $created[] = $payment;
                $paidSum += $amount;

                // P0: hard overpayment guard (financial safety). Catch within the
                // loop so a single inflated part in a mixed batch is rejected before commit.
                if ($paidSum > $orderTotal + 0.001) {
                    throw new OverpaymentException($orderTotal, $paidSum);
                }
            }

            $paymentStatus = match (true) {
                ($paidSum + $loyaltyCredit) + 0.001 < $orderTotal => 'partial',
                default => 'paid',
            };

            $order->update([
                'payment_status' => $paymentStatus,
                'locked_at' => $paymentStatus === 'paid' ? now() : $order->locked_at,
            ]);

            // Block 4.3: атомарный loyalty settle при полном закрытии чека.
            if ($paymentStatus === 'paid' && $order->customer_id) {
                app(ReceiptService::class)->settleLoyaltyForOrder(
                    $tenantId,
                    $order->fresh(),
                    $bonusToSpend,
                    null,
                    $createdBy,
                );
            }

            return $created;
        });

        // 54-ФЗ: после успешного проведения оплаты ставим фискальный чек в очередь.
        // Выполняется ВНЕ транзакции accept() — при QUEUE_CONNECTION=sync Job
        // фискализует чек немедленно (NullFiscalDriver в dev/test).
        if ($payments !== []) {
            $first = $payments[0];
            app(FiscalReceiptService::class)->createSaleReceipt(
                $tenantId,
                $first->shift_id,
                $orderId,
                $first->id,
                'sale-' . $first->id,
            );
        }

        return $payments;
    }

    public function correct(
        Payment $payment,
        float $newAmount,
        string $reason,
        int $createdBy,
    ): PaymentCorrection {
        return DB::transaction(function () use ($payment, $newAmount, $reason, $createdBy): PaymentCorrection {
            $payment = Payment::query()->withoutGlobalScopes()
                ->whereKey($payment->id)
                ->lockForUpdate()
                ->firstOrFail();

            if ($payment->shift_id) {
                $shift = CashShift::query()->withoutGlobalScopes()
                    ->whereKey($payment->shift_id)
                    ->lockForUpdate()
                    ->first();

                if ($shift !== null && ($shift->closed_at !== null || $shift->status === 'closed')) {
                    throw ShiftAlreadyClosedException::default();
                }
            }

            $oldAmount = (float) $payment->amount;

            $correction = PaymentCorrection::query()->withoutGlobalScopes()->forceCreate([
                'tenant_id' => $payment->tenant_id,
                'payment_id' => $payment->id,
                'amount' => round($newAmount - $oldAmount, 2),
                'old_amount' => $oldAmount,
                'new_amount' => $newAmount,
                'reason' => $reason,
                'created_by' => $createdBy,
            ]);

            $payment->update(['amount' => $newAmount, 'status' => 'corrected']);

            AuditLog::write(
                (int) $payment->tenant_id,
                $createdBy,
                'payment.corrected',
                Payment::class,
                (int) $payment->id,
                ['amount' => $oldAmount],
                ['amount' => $newAmount, 'correction_id' => $correction->id],
                ['shift_id' => $payment->shift_id],
                $reason,
            );

            return $correction;
        });
    }

    private function resolveOpenShift(int $tenantId, ?int $shiftId, ?int $locationId): CashShift
    {
        if ($shiftId) {
            $shift = CashShift::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->whereKey($shiftId)
                ->first();

            if ($shift !== null) {
                return $shift;
            }
        }

        $shift = CashShift::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->when($locationId, fn ($q) => $q->where('location_id', $locationId))
            ->whereNull('closed_at')
            ->where(function ($q): void {
                $q->where('status', 'opened')->orWhereNull('status');
            })
            ->latest('id')
            ->first();

        if ($shift === null) {
            throw NoActiveShiftException::default();
        }

        return $shift;
    }
}
