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

use Autometria\Exceptions\Domain\FiscalizationValidationException;
use RuntimeException;

/**
 * Сервис распределения скидок с точностью до копейки (тег 1079 по 54-ФЗ).
 *
 * Правила:
 *  - Все суммы переводятся в minor units (копейки, int) для исключения
 *    накопления ошибок float.
 *  - Скидка распределяется по позициям пропорционально их сумме; остаток
 *    копеек (rounding remainder) ложится на позицию с максимальной суммой.
 *  - Жёсткий assert: sum(line_total) == receipt_total и sum(payments) == receipt_total.
 *    При расхождении бросается FiscalizationValidationException ДО отправки в ККТ.
 */
final class FiscalDiscountService
{
    /**
     * Распределить скидку по позициям и провалидировать сходимость копеек.
     *
     * @param  array<int, array{name: string, price: float, quantity: float, vat_rate: string, discount?: float}>  $items
     * @param  list<float>  $payments
     *
     * @return array{items: list<array{name: string, price: int, quantity: int, vat_rate: string, discount: int, line_total: int}>, total: int}
     */
    public function allocate(array $items, float $receiptTotal, array $payments): array
    {
        if ($items === []) {
            return ['items' => [], 'total' => (int) round($receiptTotal * 100)];
        }

        // 1) Перевод в minor units (копейки).
        $minorItems = [];
        $rawTotals = [];
        foreach ($items as $i => $item) {
            $priceMinor = (int) round((float) $item['price'] * 100);
            $qty = (int) round((float) ($item['quantity'] ?? 1) * 1000); // точность 0.001 шт
            $qtyUnits = (int) round((float) ($item['quantity'] ?? 1));
            $lineRaw = (int) round((float) $item['price'] * 100 * $qtyUnits);
            $rawTotals[$i] = $lineRaw;
            $minorItems[$i] = [
                'name' => $item['name'] ?? ('Позиция #' . ($i + 1)),
                'price' => $priceMinor,
                'quantity' => $qtyUnits,
                'vat_rate' => $item['vat_rate'] ?? 'none',
                'discount' => 0,
                'line_total' => $lineRaw,
            ];
        }

        $grossTotal = (int) array_sum($rawTotals);
        $targetTotal = (int) round($receiptTotal * 100);

        // 2) Скидка = разница между "грязной" суммой и целевой (с учётом того,
        //    что receiptTotal уже может быть чистой после скидки).
        $discountTotal = $grossTotal - $targetTotal;

        if ($discountTotal !== 0 && $grossTotal > 0) {
            $this->distributeDiscount($minorItems, $rawTotals, $discountTotal);
        }

        // 3) Жёсткий assert сходимости позиций к total.
        $sumLines = 0;
        foreach ($minorItems as $m) {
            $sumLines += $m['line_total'];
        }
        if ($sumLines !== $targetTotal) {
            throw new FiscalizationValidationException(
                sprintf('Sum of line totals (%d) != receipt total (%d) after discount allocation.', $sumLines, $targetTotal)
            );
        }

        // 4) Жёсткий assert сходимости платежей к total.
        $sumPayments = 0;
        foreach ($payments as $p) {
            $sumPayments += (int) round((float) $p * 100);
        }
        if ($payments !== [] && $sumPayments !== $targetTotal) {
            throw new FiscalizationValidationException(
                sprintf('Sum of payments (%d) != receipt total (%d).', $sumPayments, $targetTotal)
            );
        }

        return ['items' => array_values($minorItems), 'total' => $targetTotal];
    }

    /**
     * Распределить скидку (в копейках, может быть отрицательной при наценке)
     * пропорционально сумме позиций; остаток — на позицию с максимальной суммой.
     *
     * @param  array<int, array{line_total: int, discount: int, ...}>  $minorItems
     * @param  array<int, int>  $rawTotals
     */
    private function distributeDiscount(array &$minorItems, array $rawTotals, int $discountTotal): void
    {
        $grossTotal = (int) array_sum($rawTotals);
        if ($grossTotal <= 0) {
            return;
        }

        $allocated = 0;
        $maxIdx = array_key_first($minorItems);
        foreach ($minorItems as $i => &$m) {
            $share = (int) round($discountTotal * ($rawTotals[$i] / $grossTotal));
            $m['discount'] += $share;
            $m['line_total'] -= $share;
            $allocated += $share;
            if ($rawTotals[$i] > ($rawTotals[$maxIdx] ?? 0)) {
                $maxIdx = $i;
            }
            unset($m);
        }

        // Остаток копеек (от округления) кладём на позицию с максимальной суммой.
        $remainder = $discountTotal - $allocated;
        if ($remainder !== 0 && isset($minorItems[$maxIdx])) {
            $minorItems[$maxIdx]['discount'] += $remainder;
            $minorItems[$maxIdx]['line_total'] -= $remainder;
        }
    }
}
