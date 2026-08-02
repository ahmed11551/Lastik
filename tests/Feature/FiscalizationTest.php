<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

use Autometria\Enums\FiscalReceiptStatus;
use Autometria\Jobs\FiscalizeReceiptJob;
use Autometria\Models\CashShift;
use Autometria\Models\FiscalReceipt;
use Autometria\Models\Order;
use Autometria\Models\OrderItem;
use Autometria\Models\ProductService;
use Autometria\Services\Cash\CashShiftService;
use Autometria\Services\Fiscal\FiscalDriverInterface;
use Autometria\Services\Fiscal\FiscalReceiptService;
use Autometria\Services\Fiscal\FiscalResultDto;
use Autometria\Services\PaymentService;
use Mockery;
use Tests\Support\AcceptanceFixture;
use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->fx = AcceptanceFixture::make('fiscal-'.uniqid());
    set_current_tenant_id($this->fx->tenant->id);
    actingAs($this->fx->user);
});

/**
 * Test 1: полный цикл — оплата заказа в открытой смене автоматически
 * создаёт фискальный чек и (при QUEUE_CONNECTION=sync + NullFiscalDriver)
 * фискализует его до статуса FISCALIZED с ФД/ФН/ФП.
 */
it('creates and fiscalizes a sale receipt on payment in an open shift', function (): void {
    $t = $this->fx->tenant->id;

    // Открытая смена.
    $shift = app(CashShiftService::class)->open($t, $this->fx->location->id, $this->fx->user->id, 0);

    // Товар + заказ с позициями (НДС 20%).
    $product = ProductService::query()->withoutGlobalScopes()->create([
        'tenant_id' => $t,
        'name' => 'Шина',
        'category' => 'tires',
        'type' => 'product',
        'base_price' => 4000,
        'unit' => 'шт',
    ]);

    $order = Order::query()->withoutGlobalScopes()->create([
        'tenant_id' => $t,
        'customer_id' => $this->fx->customer->id ?? null,
        'location_id' => $this->fx->location->id,
        'shift_id' => $shift->id,
        'total' => 8000,
        'status' => 'new',
        'payment_status' => 'unpaid',
    ]);

    OrderItem::query()->withoutGlobalScopes()->create([
        'tenant_id' => $t,
        'order_id' => $order->id,
        'product_id' => $product->id,
        'type' => 'product',
        'qty' => 2,
        'price' => 4000,
        'vat_rate' => '20',
        'snapshot' => ['name' => 'Шина'],
    ]);

    // Проводим оплату (валюта/метод из словаря fixture: payment_form).
    $payments = app(PaymentService::class)->accept($t, $order->id, [
        ['method' => 'cash', 'amount' => 8000],
    ], $this->fx->user->id, $shift->id);

    expect($payments)->toHaveCount(1);

    // Чек должен быть создан и фискализован (sync job + Null driver).
    $receipt = FiscalReceipt::query()->withoutGlobalScopes()
        ->where('tenant_id', $t)
        ->where('order_id', $order->id)
        ->first();

    expect($receipt)->not->toBeNull();
    expect($receipt->status)->toBe(FiscalReceiptStatus::FISCALIZED);
    expect($receipt->fiscal_document_number)->not->toBeNull();
    expect($receipt->fiscal_sign)->not->toBeNull();
    expect($receipt->qr_code_url)->not->toBeNull();
    expect($receipt->cash_shift_id)->toBe($shift->id);
    expect($receipt->payment_id)->toBe($payments[0]->id);
});

/**
 * Test 2: идемпотентность — повторный вызов FiscalizeReceiptJob с тем же
 * чеком НЕ делает повторный запрос к драйверу (проверка по mock).
 */
it('does not re-call the driver on a second job dispatch (idempotency)', function (): void {
    $t = $this->fx->tenant->id;

    $receipt = FiscalReceipt::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $t,
        'type' => 'sell',
        'status' => FiscalReceiptStatus::PENDING->value,
        'idempotency_key' => 'idem-' . uniqid(),
        'payload' => ['items' => [], 'total' => 100],
    ]);

    $driver = Mockery::mock(FiscalDriverInterface::class);
    // Ровно ОДИН вызов fiscalize на весь тест (второй handle должен пропустить).
    $driver->shouldReceive('fiscalize')
        ->once()
        ->andReturn(FiscalResultDto::success('FD1', 'FN1', 'FP1', 'https://qr/1', 'ext-1'));

    $service = new FiscalReceiptService($driver);

    // Первый прогон.
    (new FiscalizeReceiptJob($receipt->id))->handle($service);
    // Второй прогон (имитация retry/дубля) — должен пропустить по статусу FISCALIZED.
    (new FiscalizeReceiptJob($receipt->id))->handle($service);

    $receipt->refresh();
    expect($receipt->status)->toBe(FiscalReceiptStatus::FISCALIZED);
});

/**
 * Test 3: обработка сбоя ОФД — драйвер возвращает ошибку, чек уходит в
 * FAILED с error_message и attempts++, Job бросает (планируя retry по backoff).
 */
it('marks receipt FAILED with error and increments attempts on OFD failure', function (): void {
    $t = $this->fx->tenant->id;

    $receipt = FiscalReceipt::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $t,
        'type' => 'sell',
        'status' => FiscalReceiptStatus::PENDING->value,
        'idempotency_key' => 'fail-' . uniqid(),
        'payload' => ['items' => [], 'total' => 100],
    ]);

    $driver = Mockery::mock(FiscalDriverInterface::class);
    $driver->shouldReceive('fiscalize')
        ->andReturn(FiscalResultDto::failure('ATOL HTTP 500: upstream timeout'));

    $service = new FiscalReceiptService($driver);

    // Job бросает при сбое -> ловим, но статус уже записан в БД.
    expect(fn () => (new FiscalizeReceiptJob($receipt->id))->handle($service))
        ->toThrow(\RuntimeException::class);

    $receipt->refresh();
    expect($receipt->status)->toBe(FiscalReceiptStatus::FAILED);
    expect($receipt->error_message)->toContain('ATOL HTTP 500');
    expect((int) $receipt->attempts)->toBe(1);
});
