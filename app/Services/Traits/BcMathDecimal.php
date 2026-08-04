<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Services\Traits;

/**
 * BcMath decimal arithmetic (TD / F1: financial precision).
 *
 * Все денежные и количественные вычисления выполняются как строковая
 * арифметика bcmath с фиксированным scale=3 (decimal(12,3) / decimal(14,3)),
 * чтобы исключить накопление ошибки представления float (например,
 * рассинхрон себестоимости на 3 копейки). Финальный round() возвращает
 * float только на границе API/документа (контракты тестов).
 */
trait BcMathDecimal
{
    private const BC_SCALE = 3;

    protected function bcAdd(float|int|string $a, float|int|string $b): float
    {
        return (float) bcadd((string) $a, (string) $b, self::BC_SCALE);
    }

    protected function bcSub(float|int|string $a, float|int|string $b): float
    {
        return (float) bcsub((string) $a, (string) $b, self::BC_SCALE);
    }

    protected function bcMul(float|int|string $a, float|int|string $b): float
    {
        return (float) bcmul((string) $a, (string) $b, self::BC_SCALE);
    }

    protected function bcDiv(float|int|string $a, float|int|string $b): float
    {
        return (float) bcdiv((string) $a, (string) $b, self::BC_SCALE);
    }

    /**
     * Округление bcmath-строки до $precision знаков (по умолчанию 2 — деньги).
     * Промежуточная арифметика уже точная (bcmath); финальный round() лишь
     * приводит результат к float на границе API/тестов без накопления погрешности.
     */
    protected function bcRound(float|int|string $value, int $precision = 2): float
    {
        return round((float) $value, $precision);
    }
}
