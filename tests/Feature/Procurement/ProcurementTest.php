<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

use Autometria\Models\ProductService;
use Autometria\Models\PurchaseOrderDraft;
use Autometria\Models\Stock;
use Autometria\Models\StockBatch;
use Autometria\Models\StockLotDeduction;
use Autometria\Models\Supplier;
use Autometria\Models\Tenant;
use Autometria\Models\Warehouse;
use Autometria\Services\Procurement\AutoOrderGeneratorService;
use Autometria\Services\Analytics\DemandForecasterService;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->tenant = Tenant::query()->forceCreate([
        'name' => 'Proc Tenant',
        'slug' => 'proc-tenant',
        'timezone' => 'Europe/Moscow',
        'is_active' => true,
    ]);
    $this->tenantId = $this->tenant->id;

    $this->warehouse = Warehouse::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->tenantId,
        'name' => 'Склад P',
        'external_id' => 'WH-P',
        'location_id' => \Autometria\Models\Location::query()->forceCreate([
            'tenant_id' => $this->tenantId,
            'name' => 'Локация P',
        ])->id,
    ]);

    $this->supplier = Supplier::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->tenantId,
        'name' => 'Поставщик P',
        'email' => 'supplier@example.com',
        'is_active' => true,
    ]);

    $user = \Autometria\Models\User::query()->forceCreate([
        'tenant_id' => $this->tenantId,
        'name' => 'Proc User',
        'email' => 'proc@example.com',
        'password_hash' => bcrypt('secret'),
    ]);
    $this->userId = $user->id;
    actingAs($user);
});

/**
 * Test 1: AutoOrderGenerator группирует по поставщику и создаёт черновик с учётом MOQ.
 */
it('generates purchase order drafts for at-risk products', function (): void {
    $product = ProductService::query()->forceCreate([
        'tenant_id' => $this->tenantId,
        'type' => 'product',
        'name' => 'Закуп Товар',
        'article' => 'PR-1',
        'base_price' => 100.0,
        'lead_time_days' => 10,
        'safety_stock' => 50.0,
        'moq' => 20.0,
        'preferred_supplier_id' => $this->supplier->id,
        'is_active' => true,
    ]);

    $batch = StockBatch::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->tenantId,
        'product_id' => $product->id,
        'warehouse_id' => $this->warehouse->id,
        'quantity' => 1000.0,
        'cost_price' => 50.0,
        'received_at' => now()->subMonths(4),
    ]);
    StockLotDeduction::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->tenantId,
        'product_id' => $product->id,
        'stock_batch_id' => $batch->id,
        'warehouse_id' => $this->warehouse->id,
        'quantity' => 100.0,
        'unit_cost' => 50.0,
        'deducted_at' => now()->subDays(5),
    ]);
    Stock::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->tenantId,
        'product_id' => $product->id,
        'warehouse_id' => $this->warehouse->id,
        'actual' => 5.0,
        'reserved' => 0.0,
    ]);

    $service = new AutoOrderGeneratorService(new DemandForecasterService());
    $result = $service->generateForTenant($this->tenantId, 90);

    expect($result['drafts'])->toBe(1);
    expect($result['items'])->toBe(1);

    $draft = PurchaseOrderDraft::query()->withoutGlobalScopes()
        ->where('tenant_id', $this->tenantId)->first();
    expect($draft)->not->toBeNull();
    expect($draft->status)->toBe('draft');
    $item = $draft->items()->first();
    // ROP ~ 3.33*10+50 = 83.3, current 5 -> suggested 78.3, но MOQ=20, qty уже > MOQ -> 78.3
    expect($item->suggested_qty)->toBeGreaterThanOrEqual(20.0);
    expect($draft->total_amount)->toBeGreaterThan(0.0);
});

/**
 * Test 2: API generate-drafts + list + approve.
 */
it('exposes procurement api end to end', function (): void {
    $product = ProductService::query()->forceCreate([
        'tenant_id' => $this->tenantId,
        'type' => 'product',
        'name' => 'API Закуп',
        'article' => 'PR-A',
        'base_price' => 100.0,
        'lead_time_days' => 10,
        'safety_stock' => 50.0,
        'preferred_supplier_id' => $this->supplier->id,
        'is_active' => true,
    ]);
    Stock::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->tenantId,
        'product_id' => $product->id,
        'warehouse_id' => $this->warehouse->id,
        'actual' => 2.0,
        'reserved' => 0.0,
    ]);

    set_current_tenant_id($this->tenantId);
    $role = \Autometria\Models\Role::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->tenantId,
        'name' => 'Admin',
        'slug' => 'admin-proc',
        'permissions' => ['stock.view', 'stock.transfer'],
    ]);
    \Autometria\Models\User::query()->withoutGlobalScopes()->whereKey($this->userId)
        ->update(['role_id' => $role->id]);
    actingAs(\Autometria\Models\User::query()->withoutGlobalScopes()->findOrFail($this->userId));

    $gen = $this->postJson('/api/v1/procurement/generate-drafts');
    $gen->assertStatus(201);
    expect($gen->json('data.drafts'))->toBeGreaterThanOrEqual(1);

    $list = $this->getJson('/api/v1/procurement/drafts');
    $list->assertOk();
    $draftId = $list->json('data.0.id');

    $approve = $this->postJson("/api/v1/procurement/drafts/{$draftId}/approve");
    $approve->assertOk();
    expect($approve->json('data.status'))->toBe('approved');

    $csv = $this->getJson("/api/v1/procurement/drafts/{$draftId}/export.csv");
    $csv->assertOk();
});

/**
 * Test 3: email dispatch sends message and marks sent.
 */
it('dispatches draft by email', function (): void {
    Mail::fake();

    $product = ProductService::query()->forceCreate([
        'tenant_id' => $this->tenantId,
        'type' => 'product',
        'name' => 'Email Закуп',
        'article' => 'PR-E',
        'base_price' => 100.0,
        'lead_time_days' => 10,
        'safety_stock' => 50.0,
        'preferred_supplier_id' => $this->supplier->id,
        'is_active' => true,
    ]);
    Stock::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->tenantId,
        'product_id' => $product->id,
        'warehouse_id' => $this->warehouse->id,
        'actual' => 3.0,
        'reserved' => 0.0,
    ]);

    $service = new AutoOrderGeneratorService(new DemandForecasterService());
    $service->generateForTenant($this->tenantId, 90);

    $draft = PurchaseOrderDraft::query()->withoutGlobalScopes()
        ->where('tenant_id', $this->tenantId)->first();
    $dispatch = new \Autometria\Services\Procurement\SupplierDispatchService();
    $sent = $dispatch->sendByEmail($draft->id);

    expect($sent)->toBeTrue();
    Mail::assertSent(\Illuminate\Mail\Mailable::class);
    expect($draft->fresh()->status)->toBe('sent');
});
