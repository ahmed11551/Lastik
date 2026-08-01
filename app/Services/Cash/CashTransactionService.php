<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @package    Autometria\Services\Cash
 * @copyright  (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author     Себиев Ахмед Сулейманович (Chief Software Architect / Lead Developer)
 * @license    Proprietary & Confidential.
 */

declare(strict_types=1);

namespace Autometria\Services\Cash;

use Autometria\Exceptions\Domain\ShiftExpiredException;
use Autometria\Models\CashMovement;
use Autometria\Models\CashShift;
use Autometria\Support\AuditLog;

/**
 * CashTransactionService — внесения (deposit) и выемки (pay_out) наличных.
 * Делегирует блокировки/активность смены в CashShiftService (SRP).
 */
final class CashTransactionService
{
    public function __construct(
        private readonly CashShiftService $shifts = new CashShiftService,
    ) {}

    /**
     * Внесение наличных в кассу активной смены.
     */
    public function deposit(CashShift $shift, float $amount, ?string $reason = null): CashMovement
    {
        $this->shifts->assertShiftActive($shift);

        return $this->shifts->deposit($shift, $amount, $reason);
    }

    /**
     * Выемка / инкассация (pay_out) из кассы активной смены.
     */
    public function payOut(CashShift $shift, float $amount, ?string $reason = null): CashMovement
    {
        $this->shifts->assertShiftActive($shift);

        return $this->shifts->withdrawal($shift, $amount, $reason);
    }

    /**
     * Инкассация (вывоз выручки) — alias payOut с пометкой inkasso.
     */
    public function inkasso(CashShift $shift, float $amount, ?string $reason = null): CashMovement
    {
        $this->shifts->assertShiftActive($shift);

        return $this->shifts->inkasso($shift, $amount, $reason);
    }

    /**
     * Гарантирует, что смена активна (не истекла 24ч, не закрыта).
     * @throws ShiftExpiredException
     */
    public function requireActiveShift(CashShift $shift): void
    {
        $this->shifts->assertShiftActive($shift);
    }
}
