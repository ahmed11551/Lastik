<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

use Autometria\Models\Customer;
use Autometria\Models\Order;
use Autometria\Models\OrderItem;
use Autometria\Models\ProductService;
use Autometria\Models\StockBatch;
use Autometria\Models\StockLotDeduction;
use Autometria\Models\Tenant;
use Autometria\Models\Warehouse;
use Autometria\Services\Analytics\AnalyticsReportService;
use Autometria\Services\StockBatchService;
use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->tenant = Tenant::query()->forceCreate([
        'name' => 'Analytics Tenant',
        'slug' => 'analytics-tenant',
        'timezone' => 'Europe/Moscow',
        'is_active' => true,
    ]);
    $this->tenantId = $this->tenant->id;

    $this->warehouse = Warehouse::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->tenantId,
        'name' => 'Склад A',
        'external_id' => 'WH-A',
        'location_id' => \Autometria\Models\Location::query()->forceCreate([
            'tenant_id' => $this->tenantId,
            'name' => 'Локация A',
        ])->id,
    ]);

    $this->service = new AnalyticsReportService();
    $this->batchService = new StockBatchService();

    $user = \Autometria\Models\User::query()->forceCreate([
        'tenant_id' => $this->tenantId,
        'name' => 'Analytics User',
        'email' => 'analytics@example.com',
        'password_hash' => bcrypt('secret'),
    ]);
    $this->userId = $user->id;
    actingAs($user);
});

/**
 * Test 1: COGS строго по FIFO-партиям.
 * Партия №1: 10 шт. по 100 руб. Партия №2: 10 шт. по 200 руб.
 * Продажа 15 шт. по 300 руб. -> COGS = 10*100 + 5*200 = 2000,
 * Валовая прибыль = 15*300 - 2000 = 2500.
 */
it('calculates cogs strictly by fifo lots', function (): void {
    $product = ProductService::query()->forceCreate([
        'tenant_id' => $this->tenantId,
        'type' => 'product',
        'name' => 'Товар COGS',
        'article' => 'COGS-1',
        'base_price' => 300,
        'is_active' => true,
    ]);

    $this->batchService->ingress($this->tenantId, $this->warehouse->id, $product->id, 10.0, 100.0, null, $this->userId);
    $this->batchService->ingress($this->tenantId, $this->warehouse->id, $product->id, 10.0, 200.0, null, $this->userId);

    // Заказ + позиция (для привязки списания).
    $order = Order::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->tenantId,
        'customer_id' => Customer::query()->forceCreate(['tenant_id' => $this->tenantId, 'name' => 'C'])->id,
        'status' => \Autometria\Enums\OrderStatusEnum::COMPLETED->value,
        'total' => 4500.0,
    ]);
    $orderItem = OrderItem::query()->forceCreate([
        'tenant_id' => $this->tenantId,
        'order_id' => $order->id,
        'product_id' => $product->id,
        'qty' => 15.0,
        'price' => 300.0,
    ]);

    // FIFO-списание с фиксацией детализации партий.
    $this->batchService->writeOff(
        $this->tenantId,
        $this->warehouse->id,
        $product->id,
        15.0,
        $this->userId,
        $order->id,
        $orderItem->id,
    );

    // Проверка детализации списания (FIFO).
    $deductions = StockLotDeduction::query()->withoutGlobalScopes()
        ->where('tenant_id', $this->tenantId)
        ->where('order_item_id', $orderItem->id)
        ->orderBy('unit_cost', 'asc')
        ->get();
    expect($deductions)->toHaveCount(2);
    expect((float) $deductions[0]->quantity)->toBe(10.0);
    expect((float) $deductions[0]->unit_cost)->toBe(100.0);
    expect((float) $deductions[1]->quantity)->toBe(5.0);
    expect((float) $deductions[1]->unit_cost)->toBe(200.0);

    // COGS = 10*100 + 5*200 = 2000.
    $summary = $this->service->getDashboardSummary($this->tenantId);
    expect($summary['cogs'])->toBe(2000.0);
    expect($summary['gross_profit'])->toBe(2500.0);
    expect($summary['revenue'])->toBe(4500.0);
});

/**
 * Test 2: ABC/XYZ классификация по вкладу в валовую прибыль.
 */
it('classifies abc xyz correctly', function (): void {
    $products = [];
    foreach (['A' => 1000.0, 'B' => 500.0, 'C' => 100.0] as $k => $price) {
        $p = ProductService::query()->forceCreate([
            'tenant_id' => $this->tenantId,
            'type' => 'product',
            'name' => "Товар {$k}",
            'article' => "ABC-{$k}",
            'base_price' => 100.0,
            'is_active' => true,
        ]);
        $products[$k] = $p;

        $this->batchService->ingress($this->tenantId, $this->warehouse->id, $p->id, 100.0, 10.0, null, $this->userId);

        $order = Order::query()->withoutGlobalScopes()->forceCreate([
            'tenant_id' => $this->tenantId,
            'customer_id' => Customer::query()->forceCreate(['tenant_id' => $this->tenantId, 'name' => "C{$k}"])->id,
            'status' => \Autometria\Enums\OrderStatusEnum::COMPLETED->value,
            'total' => $price + 100.0,
        ]);
        $orderItem = OrderItem::query()->forceCreate([
            'tenant_id' => $this->tenantId,
            'order_id' => $order->id,
            'product_id' => $p->id,
            'qty' => 1.0,
            'price' => $price + 10.0,
        ]);

        $this->batchService->writeOff(
            $this->tenantId,
            $this->warehouse->id,
            $p->id,
            1.0,
            $this->userId,
            $order->id,
            $orderItem->id,
        );
    }

    $result = $this->service->getAbcXyzAnalysis($this->tenantId);

    $abc = $result['abc'];
    expect($abc[$products['A']->id]['abc'])->toBe('A');

    $classes = collect($abc)->pluck('abc')->unique()->sort()->values()->all();
    expect($classes)->toContain('A');
    expect($classes)->toContain('B');
    expect($classes)->toContain('C');

    // Матрица содержит хотя бы один сегмент с товаром A.
    $aSegment = 'A' . ($result['xyz'][$products['A']->id] ?? 'Z');
    expect(array_keys($result['matrix']))->toContain($aSegment);
});

/**
 * Test 3: изоляция тенантов в аналитических запросах.
 */
it('isolates analytics by tenant', function (): void {
    $product = ProductService::query()->forceCreate([
        'tenant_id' => $this->tenantId,
        'type' => 'product',
        'name' => 'Товар T1',
        'article' => 'T1',
        'base_price' => 100.0,
        'is_active' => true,
    ]);
    $this->batchService->ingress($this->tenantId, $this->warehouse->id, $product->id, 10.0, 50.0, null, $this->userId);

    $order = Order::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->tenantId,
        'customer_id' => Customer::query()->forceCreate(['tenant_id' => $this->tenantId, 'name' => 'CT1'])->id,
        'status' => \Autometria\Enums\OrderStatusEnum::COMPLETED->value,
        'total' => 1000.0,
    ]);
    $orderItem = OrderItem::query()->forceCreate([
        'tenant_id' => $this->tenantId,
        'order_id' => $order->id,
        'product_id' => $product->id,
        'qty' => 10.0,
        'price' => 100.0,
    ]);
    $this->batchService->writeOff($this->tenantId, $this->warehouse->id, $product->id, 10.0, $this->userId, $order->id, $orderItem->id);

    // Второй тенант с собственными данными.
    $tenant2 = Tenant::query()->forceCreate([
        'name' => 'Tenant 2',
        'slug' => 'tenant-2',
        'timezone' => 'Europe/Moscow',
        'is_active' => true,
    ]);
    $wh2 = Warehouse::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $tenant2->id,
        'name' => 'Склад B',
        'external_id' => 'WH-B',
        'location_id' => \Autometria\Models\Location::query()->forceCreate(['tenant_id' => $tenant2->id, 'name' => 'Локация B'])->id,
    ]);
    $product2 = ProductService::query()->forceCreate([
        'tenant_id' => $tenant2->id,
        'type' => 'product',
        'name' => 'Товар T2',
        'article' => 'T2',
        'base_price' => 200.0,
        'is_active' => true,
    ]);
    $this->batchService->ingress($tenant2->id, $wh2->id, $product2->id, 5.0, 999.0, null, $this->userId);
    $order2 = Order::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $tenant2->id,
        'customer_id' => Customer::query()->forceCreate(['tenant_id' => $tenant2->id, 'name' => 'CT2'])->id,
        'status' => \Autometria\Enums\OrderStatusEnum::COMPLETED->value,
        'total' => 5000.0,
    ]);
    $orderItem2 = OrderItem::query()->forceCreate([
        'tenant_id' => $tenant2->id,
        'order_id' => $order2->id,
        'product_id' => $product2->id,
        'qty' => 5.0,
        'price' => 1000.0,
    ]);
    $this->batchService->writeOff($tenant2->id, $wh2->id, $product2->id, 5.0, $this->userId, $order2->id, $orderItem2->id);

    $s1 = $this->service->getDashboardSummary($this->tenantId);
    $s2 = $this->service->getDashboardSummary($tenant2->id);

    // Tenant 1: COGS = 10*50 = 500.
    expect($s1['cogs'])->toBe(500.0);
    // Tenant 2: COGS = 5*999 = 4995.
    expect($s2['cogs'])->toBe(4995.0);
    // Метрики не смешиваются.
    expect($s1['cogs'])->not->toBe($s2['cogs']);

    // Проверка, что deductions tenant1 не попадают в запрос tenant2.
    $t1Deductions = StockLotDeduction::query()->withoutGlobalScopes()
        ->where('tenant_id', $this->tenantId)->count();
    $t2Deductions = StockLotDeduction::query()->withoutGlobalScopes()
        ->where('tenant_id', $tenant2->id)->count();
    expect($t1Deductions)->toBe(1);
    expect($t2Deductions)->toBe(1);
});


