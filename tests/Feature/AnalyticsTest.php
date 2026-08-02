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

/**
 * Test 4: net COGS учитывает refunded_qty; выручка — paid POS (created) и минус возвраты.
 */
it('nets cogs and revenue after partial refund', function (): void {
    $product = ProductService::query()->forceCreate([
        'tenant_id' => $this->tenantId,
        'type' => 'product',
        'name' => 'Товар Refund',
        'article' => 'REF-COGS',
        'base_price' => 300,
        'is_active' => true,
    ]);

    $this->batchService->ingress($this->tenantId, $this->warehouse->id, $product->id, 20.0, 100.0, null, $this->userId);

    $order = Order::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->tenantId,
        'customer_id' => Customer::query()->forceCreate(['tenant_id' => $this->tenantId, 'name' => 'CR'])->id,
        'status' => \Autometria\Enums\OrderStatusEnum::CREATED->value,
        'payment_status' => \Autometria\Enums\PaymentStatusEnum::PAID->value,
        'total' => 3000.0,
    ]);
    $orderItem = OrderItem::query()->forceCreate([
        'tenant_id' => $this->tenantId,
        'order_id' => $order->id,
        'product_id' => $product->id,
        'qty' => 10.0,
        'price' => 300.0,
        'snapshot' => ['warehouse_id' => $this->warehouse->id],
    ]);

    $this->batchService->writeOff(
        $this->tenantId,
        $this->warehouse->id,
        $product->id,
        10.0,
        $this->userId,
        $order->id,
        $orderItem->id,
    );

    $before = $this->service->getDashboardSummary($this->tenantId);
    expect($before['cogs'])->toBe(1000.0);
    expect($before['revenue'])->toBe(3000.0);
    expect($before['gross_profit'])->toBe(2000.0);

    $this->batchService->reverseWriteOff(
        $this->tenantId,
        $order->id,
        $orderItem->id,
        4.0,
        $this->userId,
    );

    $refund = \Autometria\Models\Refund::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->tenantId,
        'order_id' => $order->id,
        'status' => \Autometria\Enums\RefundStatusEnum::COMPLETED->value,
        'total_amount' => 1200.0,
        'created_by' => $this->userId,
    ]);
    \Autometria\Models\RefundItem::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->tenantId,
        'refund_id' => $refund->id,
        'order_item_id' => $orderItem->id,
        'product_id' => $product->id,
        'qty' => 4.0,
        'amount' => 1200.0,
    ]);

    $after = $this->service->getDashboardSummary($this->tenantId);
    // Net COGS: 10*100 - 4*100 = 600
    expect($after['cogs'])->toBe(600.0);
    // Net revenue: 3000 - 1200 = 1800
    expect($after['gross_sales'])->toBe(3000.0);
    expect($after['refunds_total'])->toBe(1200.0);
    expect($after['revenue'])->toBe(1800.0);
    expect($after['net_revenue'])->toBe(1800.0);
    expect($after['gross_profit'])->toBe(1200.0);
    expect($after['net_profit'])->toBe(1200.0);
    expect($after['margin_pct'])->toBe(66.67);

    $dashboard = $this->service->getDashboard($this->tenantId);
    expect($dashboard['net_revenue'])->toBe(1800.0);
    expect($dashboard['gross_profit'])->toBe(1200.0);
    expect($dashboard['stock_valuation_at_cost'])->toBeGreaterThan(0);
    expect($dashboard['top_products'])->not->toBeEmpty();
    expect($dashboard['abc_xyz'])->toHaveKeys(['abc', 'xyz', 'matrix']);
});

/**
 * Test 5: фильтр склада по snapshot / deductions.
 */
it('filters revenue and cogs by warehouse', function (): void {
    $whB = Warehouse::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->tenantId,
        'name' => 'Склад B',
        'external_id' => 'WH-B2',
        'location_id' => \Autometria\Models\Location::query()->forceCreate([
            'tenant_id' => $this->tenantId,
            'name' => 'Локация B',
        ])->id,
    ]);

    $product = ProductService::query()->forceCreate([
        'tenant_id' => $this->tenantId,
        'type' => 'product',
        'name' => 'Товар WH',
        'article' => 'WH-1',
        'base_price' => 100,
        'is_active' => true,
    ]);

    $this->batchService->ingress($this->tenantId, $this->warehouse->id, $product->id, 5.0, 40.0, null, $this->userId);
    $this->batchService->ingress($this->tenantId, $whB->id, $product->id, 5.0, 50.0, null, $this->userId);

    $orderA = Order::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->tenantId,
        'customer_id' => Customer::query()->forceCreate(['tenant_id' => $this->tenantId, 'name' => 'CA'])->id,
        'status' => \Autometria\Enums\OrderStatusEnum::COMPLETED->value,
        'total' => 500.0,
    ]);
    $itemA = OrderItem::query()->forceCreate([
        'tenant_id' => $this->tenantId,
        'order_id' => $orderA->id,
        'product_id' => $product->id,
        'qty' => 5.0,
        'price' => 100.0,
        'snapshot' => ['warehouse_id' => $this->warehouse->id],
    ]);
    $this->batchService->writeOff($this->tenantId, $this->warehouse->id, $product->id, 5.0, $this->userId, $orderA->id, $itemA->id);

    $orderB = Order::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->tenantId,
        'customer_id' => Customer::query()->forceCreate(['tenant_id' => $this->tenantId, 'name' => 'CB'])->id,
        'status' => \Autometria\Enums\OrderStatusEnum::COMPLETED->value,
        'total' => 800.0,
    ]);
    $itemB = OrderItem::query()->forceCreate([
        'tenant_id' => $this->tenantId,
        'order_id' => $orderB->id,
        'product_id' => $product->id,
        'qty' => 4.0,
        'price' => 200.0,
        'snapshot' => ['warehouse_id' => $whB->id],
    ]);
    $this->batchService->writeOff($this->tenantId, $whB->id, $product->id, 4.0, $this->userId, $orderB->id, $itemB->id);

    $a = $this->service->getDashboardSummary($this->tenantId, null, null, $this->warehouse->id);
    expect($a['cogs'])->toBe(200.0); // 5*40
    expect($a['revenue'])->toBe(500.0);

    $b = $this->service->getDashboardSummary($this->tenantId, null, null, $whB->id);
    expect($b['cogs'])->toBe(200.0); // 4*50
    expect($b['revenue'])->toBe(800.0);
});

/**
 * Test 6: turnover + sales series + HTTP endpoints.
 */
it('returns turnover metrics and sales series via http', function (): void {
    $this->withoutMiddleware([
        \Autometria\Http\Middleware\EnsurePermission::class,
        \Autometria\Http\Middleware\EnforceLocationAccess::class,
    ]);

    $role = \Autometria\Models\Role::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->tenantId,
        'name' => 'Admin',
        'slug' => 'admin-analytics',
        'permissions' => ['admin.dashboard'],
    ]);
    \Autometria\Models\User::query()->withoutGlobalScopes()
        ->whereKey($this->userId)
        ->update(['role_id' => $role->id]);

    $product = ProductService::query()->forceCreate([
        'tenant_id' => $this->tenantId,
        'type' => 'product',
        'name' => 'Товар Series',
        'article' => 'SER-1',
        'base_price' => 200,
        'is_active' => true,
    ]);
    $this->batchService->ingress($this->tenantId, $this->warehouse->id, $product->id, 10.0, 50.0, null, $this->userId);

    $order = Order::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->tenantId,
        'customer_id' => Customer::query()->forceCreate(['tenant_id' => $this->tenantId, 'name' => 'CS'])->id,
        'status' => \Autometria\Enums\OrderStatusEnum::COMPLETED->value,
        'total' => 1000.0,
    ]);
    $orderItem = OrderItem::query()->forceCreate([
        'tenant_id' => $this->tenantId,
        'order_id' => $order->id,
        'product_id' => $product->id,
        'qty' => 5.0,
        'price' => 200.0,
        'snapshot' => ['warehouse_id' => $this->warehouse->id],
    ]);
    $this->batchService->writeOff($this->tenantId, $this->warehouse->id, $product->id, 5.0, $this->userId, $order->id, $orderItem->id);

    $turnover = $this->service->getInventoryTurnover($this->tenantId);
    expect($turnover['cogs_period'])->toBe(250.0);
    expect($turnover['average_inventory_value'])->toBe(250.0); // 5 remaining * 50
    expect($turnover['turnover_ratio'])->toBe(1.0);
    expect($turnover['inventory_value_basis'])->toBe('current');

    $today = now()->toDateString();
    $series = $this->service->getSalesSeries($this->tenantId, $today, $today);
    expect($series)->not->toBeEmpty();
    $todayRow = collect($series)->firstWhere('date', $today);
    expect($todayRow['revenue'])->toBe(1000.0);
    expect($todayRow['cogs'])->toBe(250.0);
    expect($todayRow['gross_profit'])->toBe(750.0);

    set_current_tenant_id($this->tenantId);
    $user = \Autometria\Models\User::query()->withoutGlobalScopes()->findOrFail($this->userId);
    actingAs($user);

    $summaryRes = $this->getJson('/api/v1/analytics/dashboard-summary');
    $summaryRes->assertOk()
        ->assertJsonPath('revenue', 1000)
        ->assertJsonPath('net_revenue', 1000)
        ->assertJsonPath('cogs', 250)
        ->assertJsonPath('gross_profit', 750);

    $dashboardRes = $this->getJson('/api/v1/analytics/dashboard?from='.$today.'&to='.$today);
    $dashboardRes->assertOk()
        ->assertJsonPath('data.net_revenue', 1000)
        ->assertJsonPath('data.cogs', 250)
        ->assertJsonPath('data.net_profit', 750)
        ->assertJsonPath('data.margin_pct', 75)
        ->assertJsonPath('data.turnover_rate', 1)
        ->assertJsonPath('data.stock_value', 250);
    expect($dashboardRes->json('data.top_products'))->toBeArray()->not->toBeEmpty();
    expect($dashboardRes->json('data.abc_xyz.abc'))->toBeArray();

    $seriesRes = $this->getJson('/api/v1/analytics/sales-series?from='.$today.'&to='.$today);
    $seriesRes->assertOk();
    expect($seriesRes->json('data'))->toBeArray()->not->toBeEmpty();
});


