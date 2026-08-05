<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Services\Traits;

/**
 * BcMath decimal arithmetic (TD / F1: financial + stock qty precision).
 *
 * Внутренний API возвращает string — без (float) на цепочке вычислений.
 * Float допускается только на границе Eloquent/JSON/Pest через bcToFloat().
 */
trait BcMathDecimal
{
    private const BC_SCALE = 3;

    protected function bcNormalize(float|int|string|null $value, int $scale = self::BC_SCALE): string
    {
        if ($value === null) {
            $raw = '0';
        } elseif (is_string($value)) {
            $raw = trim($value);
            if ($raw === '' || ! is_numeric($raw)) {
                $raw = '0';
            }
        } elseif (is_int($value)) {
            $raw = (string) $value;
        } else {
            $raw = sprintf('%.14F', $value);
        }

        return bcadd($raw, '0', $scale);
    }

    protected function bcAdd(float|int|string|null $a, float|int|string|null $b, int $scale = self::BC_SCALE): string
    {
        return bcadd($this->bcNormalize($a, $scale), $this->bcNormalize($b, $scale), $scale);
    }

    protected function bcSub(float|int|string|null $a, float|int|string|null $b, int $scale = self::BC_SCALE): string
    {
        return bcsub($this->bcNormalize($a, $scale), $this->bcNormalize($b, $scale), $scale);
    }

    protected function bcMul(float|int|string|null $a, float|int|string|null $b, int $scale = self::BC_SCALE): string
    {
        return bcmul($this->bcNormalize($a, $scale), $this->bcNormalize($b, $scale), $scale);
    }

    protected function bcDiv(float|int|string|null $a, float|int|string|null $b, int $scale = self::BC_SCALE): string
    {
        $divisor = $this->bcNormalize($b, $scale);
        if (bccomp($divisor, '0', $scale) === 0) {
            return $this->bcNormalize('0', $scale);
        }

        return bcdiv($this->bcNormalize($a, $scale), $divisor, $scale);
    }

    /**
     * @return int -1 if $a < $b, 0 if equal, 1 if $a > $b
     */
    protected function bcComp(float|int|string|null $a, float|int|string|null $b, int $scale = self::BC_SCALE): int
    {
        return bccomp($this->bcNormalize($a, $scale), $this->bcNormalize($b, $scale), $scale);
    }

    protected function bcMin(float|int|string|null $a, float|int|string|null $b, int $scale = self::BC_SCALE): string
    {
        return $this->bcComp($a, $b, $scale) <= 0
            ? $this->bcNormalize($a, $scale)
            : $this->bcNormalize($b, $scale);
    }

    protected function bcMax(float|int|string|null $a, float|int|string|null $b, int $scale = self::BC_SCALE): string
    {
        return $this->bcComp($a, $b, $scale) >= 0
            ? $this->bcNormalize($a, $scale)
            : $this->bcNormalize($b, $scale);
    }

    /**
     * Half-up rounding via bcmath (no PHP round on the decision path).
     */
    protected function bcRound(float|int|string|null $value, int $precision = 2): string
    {
        $precision = max(0, $precision);
        $workScale = max($precision + 2, self::BC_SCALE + 2);
        $normalized = $this->bcNormalize($value, $workScale);
        $factor = bcpow('10', (string) $precision, 0);
        $scaled = bcmul($normalized, $factor, $workScale);

        if (bccomp($scaled, '0', $workScale) >= 0) {
            $scaled = bcadd($scaled, '0.5', $workScale);
        } else {
            $scaled = bcsub($scaled, '0.5', $workScale);
        }

        $truncated = bcadd($scaled, '0', 0);
        $result = bcdiv($truncated, $factor, $precision);

        return $this->bcNormalize($result, $precision);
    }

    /** True when |$a - $b| <= $epsilon (default qty epsilon 0.0001). */
    protected function bcAlmostZero(float|int|string|null $value, float|int|string $epsilon = '0.0001'): bool
    {
        $abs = $this->bcComp($value, '0') < 0
            ? $this->bcSub('0', $value)
            : $this->bcNormalize($value);

        return $this->bcComp($abs, $epsilon) <= 0;
    }

    /**
     * Serialize boundary only (JSON / Pest / Eloquent float casts).
     * Do not use inside arithmetic chains.
     */
    protected function bcToFloat(float|int|string|null $value): float
    {
        return (float) $this->bcNormalize($value, max(self::BC_SCALE, 4));
    }
}
