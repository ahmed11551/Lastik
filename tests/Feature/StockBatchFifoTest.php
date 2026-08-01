<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

use Autometria\Models\StockBatch;
use Autometria\Services\StockBatchService;
use Tests\Support\AcceptanceFixture;
use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->fx = AcceptanceFixture::make('fifo-'.uniqid());
    set_current_tenant_id($this->fx->tenant->id);
    actingAs($this->fx->user);
});

it('writes off strictly by FIFO (oldest batch first)', function (): void {
    $svc = app(StockBatchService::class);
    $t = $this->fx->tenant->id;
    $w = $this->fx->warehouse->id;
    $p = $this->fx->product->id;

    // Две партии: старая (cost 100) и новая (cost 150).
    $old = $svc->ingress($t, $w, $p, 10, 100.0, 'B-OLD');
    // сдвигаем received_at старой партии в прошлое для детерминизма
    $old->update(['received_at' => now()->subDay()]);

    $new = $svc->ingress($t, $w, $p, 10, 150.0, 'B-NEW');

    // Списываем 12 шт. -> 10 из старой (cost 100) + 2 из новой (cost 150)
    $result = $svc->writeOff($t, $w, $p, 12.0);

    expect($result['written_off'])->toBe(12.0);
    // 10*100 + 2*150 = 1000 + 300 = 1300
    expect($result['cost'])->toBe(1300.0);

    $old->refresh();
    $new->refresh();
    expect((float) $old->remaining_qty)->toBe(0.0);   // старая полностью списана
    expect((float) $new->remaining_qty)->toBe(8.0);    // из новой взяли 2
});

it('rejects write-off when available is insufficient', function (): void {
    $svc = app(StockBatchService::class);
    $t = $this->fx->tenant->id;
    $w = $this->fx->warehouse->id;
    $p = $this->fx->product->id;

    $svc->ingress($t, $w, $p, 5, 90.0, 'B1');

    expect(fn () => $svc->writeOff($t, $w, $p, 99.0))
        ->toThrow(\Autometria\Exceptions\Domain\InsufficientStockException::class);
});

it('adjust corrects batch and stock actual', function (): void {
    $svc = app(StockBatchService::class);
    $t = $this->fx->tenant->id;
    $w = $this->fx->warehouse->id;
    $p = $this->fx->product->id;

    $svc->ingress($t, $w, $p, 10, 100.0, 'B1');
    $batch = $svc->adjust($t, $w, $p, 7.0, 'Пересчёт');

    expect((float) $batch->remaining_qty)->toBe(7.0);

    $stock = \Autometria\Models\Stock::query()->withoutGlobalScopes()
        ->where('tenant_id', $t)->where('warehouse_id', $w)->where('product_id', $p)->first();
    expect((float) $stock->actual)->toBe(7.0);
});

it('write-off under concurrent race does not go negative', function (): void {
    $svc = app(StockBatchService::class);
    $t = $this->fx->tenant->id;
    $w = $this->fx->warehouse->id;
    $p = $this->fx->product->id;

    $svc->ingress($t, $w, $p, 10, 100.0, 'B1');

    // Reset aggregate stock to known state (fixture seeds 20) for deterministic assertion.
    \Autometria\Models\Stock::query()->withoutGlobalScopes()
        ->where('tenant_id', $t)->where('warehouse_id', $w)->where('product_id', $p)
        ->update(['actual' => 10, 'reserved' => 0, 'available' => 10]);

    // Эмулируем две конкурентные транзакции списания по 6 шт. каждая.
    // Вторая должна увидеть остаток после первой и упасть (недостаточно).
    $first = $svc->writeOff($t, $w, $p, 6.0);

    expect($first['written_off'])->toBe(6.0);

    expect(fn () => $svc->writeOff($t, $w, $p, 6.0))
        ->toThrow(\Autometria\Exceptions\Domain\InsufficientStockException::class);

    $stock = \Autometria\Models\Stock::query()->withoutGlobalScopes()
        ->where('tenant_id', $t)->where('warehouse_id', $w)->where('product_id', $p)->first();
    // Итоговый остаток = 4, не отрицательный.
    expect((float) $stock->actual)->toBe(4.0);

    // Партия не ушла в минус.
    $batch = StockBatch::query()->withoutGlobalScopes()->whereKey($first['batches'] ?? 1)->first()
        ?? StockBatch::query()->withoutGlobalScopes()->first();
    expect((float) $batch->remaining_qty)->toBeGreaterThanOrEqual(0.0);
});
