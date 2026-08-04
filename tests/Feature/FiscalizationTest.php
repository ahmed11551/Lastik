<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

use Autometria\Enums\FiscalReceiptStatus;
use Autometria\Exceptions\Domain\FiscalizationValidationException;
use Autometria\Jobs\FiscalizeReceiptJob;
use Autometria\Jobs\ReconcileReceiptJob;
use Autometria\Models\CashShift;
use Autometria\Models\FiscalReceipt;
use Autometria\Models\Order;
use Autometria\Models\OrderItem;
use Autometria\Models\ProductService;
use Autometria\Services\Cash\CashShiftService;
use Autometria\Services\Fiscal\FiscalDiscountService;
use Autometria\Services\Fiscal\FiscalDriverInterface;
use Autometria\Services\Fiscal\FiscalReceiptService;
use Autometria\Services\Fiscal\FiscalResultDto;
use Autometria\Services\PaymentService;
use Mockery;
use Tests\Support\AcceptanceFixture;
use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->fx = AcceptanceFixture::make('fiscal2-'.uniqid());
    set_current_tenant_id($this->fx->tenant->id);
    actingAs($this->fx->user);
});

/**
 * Test 1: базовый успешный прогон через NullFiscalDriver.
 * Чек PENDING -> IN_PROGRESS (claim) -> FISCALIZED с ФД/ФН/ФП.
 */
it('test_successful_fiscalization_flow', function (): void {
    $t = $this->fx->tenant->id;

    $product = ProductService::query()->withoutGlobalScopes()->create([
        'tenant_id' => $t, 'name' => 'Шина', 'category' => 'tires',
        'type' => 'product', 'base_price' => 4000, 'unit' => 'шт',
    ]);

    $order = Order::query()->withoutGlobalScopes()->create([
        'tenant_id' => $t, 'customer_id' => $this->fx->customer->id ?? null,
        'location_id' => $this->fx->location->id, 'total' => 8000,
        'status' => 'new', 'payment_status' => 'unpaid',
    ]);

    OrderItem::query()->withoutGlobalScopes()->create([
        'tenant_id' => $t, 'order_id' => $order->id, 'product_id' => $product->id,
        'type' => 'product', 'qty' => 2, 'price' => 4000, 'vat_rate' => '20',
        'snapshot' => ['name' => 'Шина'],
    ]);

    $shift = app(CashShiftService::class)->open($t, $this->fx->location->id, $this->fx->user->id, 0);

    $payments = app(PaymentService::class)->accept($t, $order->id, [
        ['method' => 'cash', 'amount' => 8000],
    ], $this->fx->user->id, $shift->id);

    $receipt = FiscalReceipt::query()->withoutGlobalScopes()
        ->where('tenant_id', $t)->where('order_id', $order->id)->first();

    expect($receipt)->not->toBeNull();
    expect($receipt->status)->toEqual(FiscalReceiptStatus::FISCALIZED);
    expect($receipt->fd_number)->not->toBeNull();
    expect($receipt->fn_number)->not->toBeNull();
    expect($receipt->fp_value)->not->toBeNull();
    expect($receipt->qr_code_url)->not->toBeNull();
    expect($receipt->locked_at)->toBeNull(); // снят после финализации
    expect($receipt->payment_id)->toBe($payments[0]->id);
});

/**
 * Test 2: атомарный claim предотвращает двойную фискализацию под race.
 * Два одновременных вызова handle -> строго 1 вызов драйвера.
 */
it('test_claim_update_prevents_double_fiscalization_under_race', function (): void {
    $t = $this->fx->tenant->id;

    $receipt = FiscalReceipt::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $t,
        'operation' => 'sell',
        'status' => FiscalReceiptStatus::PENDING->value,
        'idempotency_key' => 'race-' . uniqid(),
        'driver_request_id' => (string) \Illuminate\Support\Str::uuid(),
        'total_amount' => 100,
        'payload_snapshot' => ['items' => [], 'total' => 10000],
    ]);

    $driver = Mockery::mock(FiscalDriverInterface::class);
    // Ровно ОДИН вызов sell на весь тест (второй handle должен получить 0 rows в claim).
    $driver->shouldReceive('sell')
        ->once()
        ->andReturn(FiscalResultDto::success('FD1', 'FN1', 'FP1', 'https://qr/1', (string) $receipt->driver_request_id));

    $service = new FiscalReceiptService($driver);

    // Два "одновременных" воркера.
    (new FiscalizeReceiptJob($receipt->id, $receipt->tenant_id))->handle($service);
    (new FiscalizeReceiptJob($receipt->id, $receipt->tenant_id))->handle($service);

    $receipt->refresh();
    // shouldReceive('sell')->once() выше гарантирует ровно 1 вызов драйвера:
    // второй handle получает 0 rows в claim (статус уже FISCALIZED) и не зовёт sell.
    expect($receipt->status)->toEqual(FiscalReceiptStatus::FISCALIZED);
});

/**
 * Test 3: симуляция обрыва сети -> NEEDS_RECONCILE -> успешная сверка.
 */
it('test_network_timeout_transitions_to_needs_reconcile_and_reconciles_successfully', function (): void {
    $t = $this->fx->tenant->id;

    $receipt = FiscalReceipt::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $t,
        'operation' => 'sell',
        'status' => FiscalReceiptStatus::PENDING->value,
        'idempotency_key' => 'timeout-' . uniqid(),
        'driver_request_id' => (string) \Illuminate\Support\Str::uuid(),
        'total_amount' => 100,
        // Флаг симуляции обрыва сети в sell().
        'payload_snapshot' => ['items' => [], 'total' => 10000, 'simulate_timeout' => true],
    ]);

    $driver = Mockery::mock(FiscalDriverInterface::class);
    $driver->shouldReceive('sell')
        ->once()
        ->andThrow(\Autometria\Exceptions\Domain\FiscalNetworkTimeoutException::class);
    // Сверка находит чек в ККТ -> success.
    $driver->shouldReceive('checkStatus')
        ->once()
        ->andReturn(FiscalResultDto::success('9', 'FN9', 'FP9', 'https://qr/9', (string) $receipt->driver_request_id));

    $service = new FiscalReceiptService($driver);

    \Illuminate\Support\Facades\Queue::fake();

    // Первый прогон: sell бросает timeout -> NEEDS_RECONCILE + планируется ReconcileReceiptJob.
    (new FiscalizeReceiptJob($receipt->id, $receipt->tenant_id))->handle($service);
    $receipt->refresh();
    expect($receipt->status)->toEqual(FiscalReceiptStatus::NEEDS_RECONCILE);
    \Illuminate\Support\Facades\Queue::assertPushed(ReconcileReceiptJob::class, fn ($job) => $job->fiscalReceiptId === $receipt->id);

    // Имитация reconcile worker (задача выполняется отдельно): checkStatus -> FISCALIZED.
    (new ReconcileReceiptJob($receipt->id))->handle($service);
    $receipt->refresh();
    expect($receipt->status)->toEqual(FiscalReceiptStatus::FISCALIZED);
    expect($receipt->fd_number)->toEqual(9);
});

/**
 * Test 4: сходимость копеек по тегу 1079 при распределении сложных скидок.
 */
it('test_discount_penny_rounding_matches_total_exactly', function (): void {
    $service = new FiscalDiscountService();

    // Сложная скидка: 3 позиции, грязная сумма 100.00, чистая 97.33 (скидка 2.67).
    $items = [
        ['name' => 'A', 'price' => 33.34, 'quantity' => 1, 'vat_rate' => '20'],
        ['name' => 'B', 'price' => 33.33, 'quantity' => 1, 'vat_rate' => '20'],
        ['name' => 'C', 'price' => 33.33, 'quantity' => 1, 'vat_rate' => 'none'],
    ];
    $payments = [97.33];

    $result = $service->allocate($items, 97.33, $payments);

    // Сходимость: sum(line_total) == total, sum(payments) == total (в копейках).
    $sumLines = 0;
    foreach ($result['items'] as $it) {
        $sumLines += $it['line_total'];
    }
    expect($sumLines)->toBe(9733); // 97.33 руб
    expect($result['total'])->toBe(9733);

    // Каждая позиция неотрицательна (скидка не уводит в минус).
    foreach ($result['items'] as $it) {
        expect($it['line_total'])->toBeGreaterThanOrEqual(0);
    }
});

/**
 * Test 4b: расхождение сумм бросает FiscalizationValidationException ДО ККТ.
 */
it('test_discount_mismatch_throws_validation', function (): void {
    $service = new FiscalDiscountService();

    $items = [
        ['name' => 'A', 'price' => 50.00, 'quantity' => 1, 'vat_rate' => '20'],
        ['name' => 'B', 'price' => 50.00, 'quantity' => 1, 'vat_rate' => '20'],
    ];
    // Платёж не бьётся с total -> должен бросить.
    $payments = [90.00];

    expect(fn () => $service->allocate($items, 100.00, $payments))
        ->toThrow(FiscalizationValidationException::class);
});
