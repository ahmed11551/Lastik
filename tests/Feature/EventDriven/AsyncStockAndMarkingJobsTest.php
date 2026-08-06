<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

use Autometria\Events\ReceiptFiscalizedEvent;
use Autometria\Events\StockUpdatedEvent;
use Autometria\Jobs\ReserveStockJob;
use Autometria\Jobs\SyncMarkingJob;
use Autometria\Services\StockBatchService;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Tests\Support\AcceptanceFixture;

use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->fx = AcceptanceFixture::make('async-fifo-'.uniqid());
    set_current_tenant_id($this->fx->tenant->id);
    actingAs($this->fx->user);
});

it('dispatches ReserveStockJob onto stock-reservations queue', function (): void {
    Queue::fake();

    ReserveStockJob::dispatch(
        $this->fx->tenant->id,
        $this->fx->warehouse->id,
        $this->fx->product->id,
        '3.000',
        $this->fx->user->id,
    );

    Queue::assertPushedOn('stock-reservations', ReserveStockJob::class);
});

it('dispatches SyncMarkingJob onto marking-sync queue', function (): void {
    Queue::fake();

    SyncMarkingJob::dispatch(
        $this->fx->tenant->id,
        '010460043900001421TESTSERIAL918000',
        $this->fx->product->id,
    );

    Queue::assertPushedOn('marking-sync', SyncMarkingJob::class);
});

it('ReserveStockJob FIFO write-off is BCMath-exact under async handle()', function (): void {
    Event::fake([StockUpdatedEvent::class]);

    $svc = app(StockBatchService::class);
    $t = $this->fx->tenant->id;
    $w = $this->fx->warehouse->id;
    $p = $this->fx->product->id;

    $old = $svc->ingress($t, $w, $p, '10', '100.00', 'ASYNC-OLD');
    $old->update(['received_at' => now()->subDay()]);
    $svc->ingress($t, $w, $p, '10', '150.00', 'ASYNC-NEW');

    $job = new ReserveStockJob($t, $w, $p, '12', $this->fx->user->id);
    $result = $job->handle($svc);

    // 10*100 + 2*150 = 1300
    expect((float) $result['written_off'])->toBe(12.0);
    expect((float) $result['cost'])->toBe(1300.0);

    $old->refresh();
    expect((float) $old->remaining_qty)->toBe(0.0);

    Event::assertDispatched(StockUpdatedEvent::class, function (StockUpdatedEvent $e) use ($t, $w, $p): bool {
        return $e->tenantId === $t
            && $e->warehouseId === $w
            && $e->productId === $p
            && $e->writtenOff !== '';
    });
});

it('ReceiptFiscalizedEvent implements broadcast contract', function (): void {
    $event = new ReceiptFiscalizedEvent(
        tenantId: 1,
        fiscalReceiptId: 99,
        orderId: 7,
        fdNumber: '123',
        fnNumber: 'FN-1',
    );

    expect($event->broadcastAs())->toBe('receipt.fiscalized');
    expect($event->broadcastWith()['fiscal_receipt_id'])->toBe(99);
    expect($event->broadcastOn())->not->toBeEmpty();
});
