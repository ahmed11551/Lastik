<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Tests\Unit;

use Autometria\Services\Traits\BcMathDecimal;
use PHPUnit\Framework\TestCase;

/**
 * Доказывает, что арифметика BcMathDecimal свободна от накопления
 * ошибки представления float, в отличие от нативных операций PHP.
 *
 * Внутренний API возвращает string; граница — bcToFloat().
 */
final class BcMathDecimalTraitTest extends TestCase
{
    private object $calc;

    protected function setUp(): void
    {
        $this->calc = new class () {
            use BcMathDecimal;

            public function add(float|int|string $a, float|int|string $b): string
            {
                return $this->bcAdd($a, $b);
            }

            public function sub(float|int|string $a, float|int|string $b): string
            {
                return $this->bcSub($a, $b);
            }

            public function mul(float|int|string $a, float|int|string $b): string
            {
                return $this->bcMul($a, $b);
            }

            public function div(float|int|string $a, float|int|string $b): string
            {
                return $this->bcDiv($a, $b);
            }

            public function round(float|int|string $v, int $p = 2): string
            {
                return $this->bcRound($v, $p);
            }

            public function comp(float|int|string $a, float|int|string $b): int
            {
                return $this->bcComp($a, $b);
            }

            public function toFloat(float|int|string $v): float
            {
                return $this->bcToFloat($v);
            }
        };
    }

    public function test_bc_add_eliminates_float_representation_error(): void
    {
        $native = 0.1 + 0.2;
        $this->assertNotSame(0.3, $native, 'Санитарный check: нативный float действительно неточен');

        $bc = $this->calc->add(0.1, 0.2);
        $this->assertSame('0.300', $bc);
        $this->assertSame(0.3, $this->calc->toFloat($bc));
    }

    public function test_bc_sub_compound_is_exact(): void
    {
        $native = (0.1 * 3) - 0.3;
        $this->assertNotSame(0.0, $native, 'Санитарный check: нативный float даёт хвост');

        $bc = $this->calc->sub($this->calc->mul(0.1, 3), 0.3);
        $this->assertSame('0.000', $bc);
        $this->assertSame(0.0, $this->calc->toFloat($bc));
    }

    public function test_bc_mul_precision_for_money(): void
    {
        $bc = $this->calc->mul(19.99, 3);
        $this->assertSame('59.970', $bc);
        $this->assertSame(59.97, $this->calc->toFloat($bc));
    }

    public function test_bc_div_rounds_to_scale(): void
    {
        $bc = $this->calc->round($this->calc->div(100, 3));
        $this->assertSame('33.33', $bc);
    }

    public function test_bc_round_half_up_is_deterministic(): void
    {
        $bc = $this->calc->round('0.125', 2);
        $this->assertSame('0.13', $bc);
    }

    public function test_bc_comp_uses_normalized_scale(): void
    {
        $this->assertSame(0, $this->calc->comp(0.1 + 0.2, 0.3));
        $this->assertSame(-1, $this->calc->comp(0.1, 0.2));
        $this->assertSame(1, $this->calc->comp(0.2, 0.1));
    }

    public function test_accumulation_of_many_small_amounts_is_exact(): void
    {
        $total = '0';
        for ($i = 0; $i < 100; $i++) {
            $total = $this->calc->add($total, 0.01);
        }
        $this->assertSame('1.000', $total);
        $this->assertSame(1.0, $this->calc->toFloat($total));
    }
}
