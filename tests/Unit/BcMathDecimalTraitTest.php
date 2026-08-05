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
 * Ключевой инвариант: bcAdd(0.1, 0.2) строго равен 0.3 (без хвоста
 * 0.30000000000000004), а компаунд-вычисления не расходятся с
 * математическим ожиданием до копейки.
 */
final class BcMathDecimalTraitTest extends TestCase
{
    private object $calc;

    protected function setUp(): void
    {
        // Trait содержит protected-методы — оборачиваем через анонимный класс
        // с публичными прокси (сам trait вызывается изнутри, как в сервисах).
        $this->calc = new class () {
            use BcMathDecimal;

            public function add(float|int|string $a, float|int|string $b): float
            {
                return $this->bcAdd($a, $b);
            }

            public function sub(float|int|string $a, float|int|string $b): float
            {
                return $this->bcSub($a, $b);
            }

            public function mul(float|int|string $a, float|int|string $b): float
            {
                return $this->bcMul($a, $b);
            }

            public function div(float|int|string $a, float|int|string $b): float
            {
                return $this->bcDiv($a, $b);
            }

            public function round(float|int|string $v, int $p = 2): float
            {
                return $this->bcRound($v, $p);
            }
        };
    }

    public function test_bc_add_eliminates_float_representation_error(): void
    {
        $native = 0.1 + 0.2;
        $this->assertNotSame(0.3, $native, 'Санитарный check: нативный float действительно неточен');

        $bc = $this->calc->add(0.1, 0.2);
        $this->assertSame(0.3, $bc, 'bcAdd(0.1, 0.2) должен дать ровно 0.3');
    }

    public function test_bc_sub_compound_is_exact(): void
    {
        $native = (0.1 * 3) - 0.3;
        $this->assertNotSame(0.0, $native, 'Санитарный check: нативный float даёт хвост');

        $bc = $this->calc->sub($this->calc->mul(0.1, 3), 0.3);
        $this->assertSame(0.0, $bc);
    }

    public function test_bc_mul_precision_for_money(): void
    {
        $bc = $this->calc->mul(19.99, 3);
        $this->assertSame(59.97, $bc, '19.99 * 3 = 59.97 без расхождения');
    }

    public function test_bc_div_rounds_to_scale(): void
    {
        $bc = $this->calc->round($this->calc->div(100, 3));
        $this->assertSame(33.33, $bc);
    }

    public function test_bc_round_banker_case_is_deterministic(): void
    {
        $bc = $this->calc->round('0.125', 2);
        $this->assertSame(0.13, $bc);
    }

    public function test_accumulation_of_many_small_amounts_is_exact(): void
    {
        $total = 0.0;
        for ($i = 0; $i < 100; $i++) {
            $total = $this->calc->add($total, 0.01);
        }
        $this->assertSame(1.0, $total, 'Сумма 100 × 0.01 должна быть ровно 1.00');
    }
}
