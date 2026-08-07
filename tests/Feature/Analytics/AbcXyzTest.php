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
use Autometria\Models\ProductClassification;
use Autometria\Models\ProductService;
use Autometria\Models\StockBatch;
use Autometria\Models\StockLotDeduction;
use Autometria\Models\Tenant;
use Autometria\Models\Warehouse;
use Autometria\Services\Analytics\AbcXyzCalculatorService;
use Autometria\Services\StockBatchService;
use Autometria\Support\helpers;
use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->tenant = Tenant::query()->forceCreate([
        'name' => 'ABC Tenant',
        'slug' => 'abc-tenant',
        'timezone' => 'Europe/Moscow',
        'is_active' => true,
    ]);
    $this->tenantId = $this->tenant->id;

    $this->warehouse = Warehouse::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->tenantId,
        'name' => 'Склад ABC',
        'external_id' => 'WH-ABC',
        'location_id' => \Autometria\Models\Location::query()->forceCreate([
            'tenant_id' => $this->tenantId,
            'name' => 'Локация ABC',
        ])->id,
    ]);

    $this->batchService = new StockBatchService();

    $user = \Autometria\Models\User::query()->forceCreate([
        'tenant_id' => $this->tenantId,
        'name' => 'ABC User',
        'email' => 'abc@example.com',
        'password_hash' => bcrypt('secret'),
    ]);
    $this->userId = $user->id;
    actingAs($user);
});

/**
 * Test 1: ABC распределяет товары по вкладу в выручку (A≤80%, B≤95%, C>95%).
 * Товар A даёт 1000 прибыли, B — 200, C — 20. Доля накопительно: A=82%, B=98%, C=100%.
 */
it('classifies products into ABC by revenue contribution', function (): void {
    $prices = ['A' => 1000.0, 'B' => 200.0, 'C' => 20.0];
    $products = [];
    foreach ($prices as $k => $profit) {
        $p = ProductService::query()->forceCreate([
            'tenant_id' => $this->tenantId,
            'type' => 'product',
            'name' => "ABC Товар {$k}",
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
            'payment_status' => \Autometria\Enums\PaymentStatusEnum::PAID->value,
            'total' => $profit + 100.0,
        ]);
        $orderItem = OrderItem::query()->forceCreate([
            'tenant_id' => $this->tenantId,
            'order_id' => $order->id,
            'product_id' => $p->id,
            'qty' => 1.0,
            'price' => $profit + 10.0,
        ]);
        $this->batchService->writeOff($this->tenantId, $this->warehouse->id, $p->id, 1.0, $this->userId, $order->id, $orderItem->id);
    }

    $service = new AbcXyzCalculatorService(new \Autometria\Services\Analytics\AnalyticsReportService());
    $result = $service->calculateForTenant($this->tenantId, 90);

    expect($result['upserted'])->toBe(3);
    expect($result['a'])->toBe(1);
    expect($result['b'])->toBe(1);
    expect($result['c'])->toBe(1);

    $classA = ProductClassification::query()->withoutGlobalScopes()
        ->where('tenant_id', $this->tenantId)
        ->where('product_id', $products['A']->id)
        ->first();
    expect($classA->abc_class)->toBe('A');
    expect($classA->revenue_share)->toBeGreaterThan(50.0);
});

/**
 * Test 2: XYZ по коэффициенту вариации помесячного спроса.
 * X — равномерно (CV<10%), Z — скачкообразно (CV>25%).
 */
it('classifies products into XYZ by demand variation coefficient', function (): void {
    $stable = ProductService::query()->forceCreate([
        'tenant_id' => $this->tenantId,
        'type' => 'product',
        'name' => 'Стабильный',
        'article' => 'XYZ-X',
        'base_price' => 100.0,
        'is_active' => true,
    ]);
    $volatile = ProductService::query()->forceCreate([
        'tenant_id' => $this->tenantId,
        'type' => 'product',
        'name' => 'Скачкообразный',
        'article' => 'XYZ-Z',
        'base_price' => 100.0,
        'is_active' => true,
    ]);

    // Стабильный: 100,100,100,100 по 4 месяцам -> CV = 0 -> X
    $batch = StockBatch::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->tenantId,
        'product_id' => $stable->id,
        'warehouse_id' => $this->warehouse->id,
        'quantity' => 2000.0,
        'cost_price' => 10.0,
        'received_at' => now()->subMonths(4),
    ]);
    $months = [now()->subMonths(3), now()->subMonths(2), now()->subMonths(1), now()];
    foreach ($months as $m) {
        StockLotDeduction::query()->withoutGlobalScopes()->forceCreate([
            'tenant_id' => $this->tenantId,
            'product_id' => $stable->id,
            'stock_batch_id' => $batch->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => 100.0,
            'unit_cost' => 10.0,
            'deducted_at' => $m,
        ]);
    }

    // Скачкообразный: 10,10,10,1000 -> CV высокий -> Z
    $volQtys = [10.0, 10.0, 10.0, 1000.0];
    foreach ($months as $i => $m) {
        StockLotDeduction::query()->withoutGlobalScopes()->forceCreate([
            'tenant_id' => $this->tenantId,
            'product_id' => $volatile->id,
            'stock_batch_id' => $batch->id,
            'warehouse_id' => $this->warehouse->id,
            'quantity' => $volQtys[$i],
            'unit_cost' => 10.0,
            'deducted_at' => $m,
        ]);
    }

    $service = new AbcXyzCalculatorService(new \Autometria\Services\Analytics\AnalyticsReportService());
    $service->calculateForTenant($this->tenantId, 120);

    $xC = ProductClassification::query()->withoutGlobalScopes()
        ->where('tenant_id', $this->tenantId)->where('product_id', $stable->id)->first();
    $zC = ProductClassification::query()->withoutGlobalScopes()
        ->where('tenant_id', $this->tenantId)->where('product_id', $volatile->id)->first();

    expect($xC->xyz_class)->toBe('X');
    expect($xC->variation_coefficient)->toBeLessThan(10.0);
    expect($zC->xyz_class)->toBe('Z');
    expect($zC->variation_coefficient)->toBeGreaterThan(25.0);
});

/**
 * Test 3: GET /api/v1/analytics/abc-xyz фильтрует по class и пагинируется.
 */
it('exposes matrix via API with class filter and pagination', function (): void {
    $p = ProductService::query()->forceCreate([
        'tenant_id' => $this->tenantId,
        'type' => 'product',
        'name' => 'API Товар',
        'article' => 'API-1',
        'base_price' => 100.0,
        'is_active' => true,
    ]);
    $this->batchService->ingress($this->tenantId, $this->warehouse->id, $p->id, 100.0, 10.0, null, $this->userId);
    $order = Order::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->tenantId,
        'customer_id' => Customer::query()->forceCreate(['tenant_id' => $this->tenantId, 'name' => 'CAPI'])->id,
        'status' => \Autometria\Enums\OrderStatusEnum::COMPLETED->value,
        'payment_status' => \Autometria\Enums\PaymentStatusEnum::PAID->value,
        'total' => 500.0,
    ]);
    $orderItem = OrderItem::query()->forceCreate([
        'tenant_id' => $this->tenantId,
        'order_id' => $order->id,
        'product_id' => $p->id,
        'qty' => 5.0,
        'price' => 100.0,
    ]);
    $this->batchService->writeOff($this->tenantId, $this->warehouse->id, $p->id, 5.0, $this->userId, $order->id, $orderItem->id);

    (new AbcXyzCalculatorService(new \Autometria\Services\Analytics\AnalyticsReportService()))
        ->calculateForTenant($this->tenantId, 90);

    $role = \Autometria\Models\Role::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->tenantId,
        'name' => 'Admin',
        'slug' => 'admin-abc',
        'permissions' => ['admin.dashboard'],
    ]);
    \Autometria\Models\User::query()->withoutGlobalScopes()
        ->whereKey($this->userId)
        ->update(['role_id' => $role->id]);
    $user = \Autometria\Models\User::query()->withoutGlobalScopes()->findOrFail($this->userId);
    actingAs($user);

    set_current_tenant_id($this->tenantId);

    $response = $this->getJson('/api/v1/analytics/abc-xyz?class=A');
    $response->assertOk();
    $json = $response->json();
    expect($json['meta']['total'])->toBeGreaterThanOrEqual(1);
    foreach ($json['data'] as $row) {
        expect($row['abc_class'])->toBe('A');
    }

    $all = $this->getJson('/api/v1/analytics/abc-xyz');
    $all->assertOk();
    expect($all->json('meta.total'))->toBeGreaterThanOrEqual(1);
});

/**
 * Test 4: POST /api/v1/analytics/abc-xyz/recalculate ставит фоновую задачу.
 */
it('queues recalculation job via API', function (): void {
    $role = \Autometria\Models\Role::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->tenantId,
        'name' => 'Admin',
        'slug' => 'admin-abc-rec',
        'permissions' => ['admin.dashboard'],
    ]);
    \Autometria\Models\User::query()->withoutGlobalScopes()
        ->whereKey($this->userId)
        ->update(['role_id' => $role->id]);
    $user = \Autometria\Models\User::query()->withoutGlobalScopes()->findOrFail($this->userId);
    actingAs($user);

    set_current_tenant_id($this->tenantId);

    \Illuminate\Support\Facades\Queue::fake();
    $response = $this->postJson('/api/v1/analytics/abc-xyz/recalculate', ['period_days' => 90]);
    $response->assertStatus(202)->assertJsonPath('data.queued', true);

    \Illuminate\Support\Facades\Queue::assertPushed(\Autometria\Jobs\CalculateAbcXyzJob::class, function ($job) {
        return $job->tenantId === $this->tenantId && $job->periodDays === 90;
    });
});
