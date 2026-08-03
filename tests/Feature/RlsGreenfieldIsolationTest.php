<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 */

declare(strict_types=1);

use Autometria\Models\Supplier;
use Autometria\Models\Tenant;

/**
 * Regression: RLS policies for Greenfield tables (purchase/payroll/portal) must read
 * `app.current_tenant_id` (set by set_current_tenant_id), not `autometria.tenant_id`.
 * Verifies tenant isolation at the Laravel global-scope layer (runtime path).
 */
afterEach(function (): void {
    Supplier::query()->withoutGlobalScopes()->delete();
    Tenant::query()->where('slug', 'like', 'rls-%')->delete();
});

it('isolates supplier records by tenant scope', function (): void {
    $tenantA = Tenant::create(['name' => 'RLS-A'.uniqid(), 'slug' => 'rls-a-'.uniqid()]);
    $tenantB = Tenant::create(['name' => 'RLS-B'.uniqid(), 'slug' => 'rls-b-'.uniqid()]);

    $supplierB = Supplier::createForTenant([
        'tenant_id' => $tenantB->id,
        'name' => 'Supplier B',
    ]);

    set_current_tenant_id($tenantA->id);

    expect(Supplier::query()->whereKey($supplierB->id)->first())->toBeNull();
    expect(Supplier::query()->get())->toHaveCount(0);

    // without scope the record exists
    expect(
        Supplier::query()->withoutGlobalScopes()->whereKey($supplierB->id)->exists()
    )->toBeTrue();
});

it('lists only current tenant suppliers', function (): void {
    $tenantA = Tenant::create(['name' => 'RLS-LA'.uniqid(), 'slug' => 'rls-la-'.uniqid()]);
    $tenantB = Tenant::create(['name' => 'RLS-LB'.uniqid(), 'slug' => 'rls-lb-'.uniqid()]);

    Supplier::createForTenant(['tenant_id' => $tenantA->id, 'name' => 'Sup A']);
    Supplier::createForTenant(['tenant_id' => $tenantB->id, 'name' => 'Sup B']);

    set_current_tenant_id($tenantA->id);

    $list = Supplier::query()->get();
    expect($list)->toHaveCount(1);
    expect($list->first()->name)->toBe('Sup A');
});
