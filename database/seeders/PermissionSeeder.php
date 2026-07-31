<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Permission;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class PermissionSeeder extends Seeder
{
    public const PERMISSIONS = [
        ['section' => 'orders', 'slug' => 'orders.view', 'action' => 'view'],
        ['section' => 'orders', 'slug' => 'orders.create', 'action' => 'create'],
        ['section' => 'orders', 'slug' => 'orders.update', 'action' => 'update'],
        ['section' => 'orders', 'slug' => 'orders.cancel', 'action' => 'cancel'],
        ['section' => 'payments', 'slug' => 'payments.create', 'action' => 'create'],
        ['section' => 'payments', 'slug' => 'payments.correct', 'action' => 'correct'],
        ['section' => 'shifts', 'slug' => 'shifts.create', 'action' => 'create'],
        ['section' => 'shifts', 'slug' => 'shifts.close', 'action' => 'close'],
        ['section' => 'stock', 'slug' => 'stock.transfer', 'action' => 'transfer'],
        ['section' => 'stock', 'slug' => 'stock.import', 'action' => 'import'],
        ['section' => 'customers', 'slug' => 'customers.view', 'action' => 'view'],
        ['section' => 'customers', 'slug' => 'customers.create', 'action' => 'create'],
        ['section' => 'customers', 'slug' => 'customers.update', 'action' => 'update'],
        ['section' => 'vehicles', 'slug' => 'vehicles.view', 'action' => 'view'],
        ['section' => 'vehicles', 'slug' => 'vehicles.create', 'action' => 'create'],
        ['section' => 'vehicles', 'slug' => 'vehicles.update', 'action' => 'update'],
        ['section' => 'warehouses', 'slug' => 'warehouses.view', 'action' => 'view'],
        ['section' => 'warehouses', 'slug' => 'warehouses.create', 'action' => 'create'],
        ['section' => 'warehouses', 'slug' => 'warehouses.update', 'action' => 'update'],
        ['section' => 'products', 'slug' => 'products.view', 'action' => 'view'],
        ['section' => 'products', 'slug' => 'products.create', 'action' => 'create'],
        ['section' => 'products', 'slug' => 'products.update', 'action' => 'update'],
        ['section' => 'settings', 'slug' => 'settings.view', 'action' => 'view'],
        ['section' => 'settings', 'slug' => 'settings.update', 'action' => 'update'],
        ['section' => 'modules', 'slug' => 'modules.view', 'action' => 'view'],
        ['section' => 'modules', 'slug' => 'modules.update', 'action' => 'update'],
        ['section' => 'admin', 'slug' => 'admin.dashboard', 'action' => 'view'],
        ['section' => 'locations', 'slug' => 'locations.all', 'action' => 'all'],
        ['section' => 'stock', 'slug' => 'stock.view', 'action' => 'view'],
        ['section' => 'users', 'slug' => 'users.view', 'action' => 'view'],
    ];

    public function run(): void
    {
        $tenants = DB::table('tenants')->get(['id'])->pluck('id');

        foreach ($tenants as $tenantId) {
            foreach (self::PERMISSIONS as $permission) {
                Permission::updateOrCreate(
                    [
                        'tenant_id' => $tenantId,
                        'slug' => $permission['slug'],
                    ],
                    $permission + ['tenant_id' => $tenantId]
                );
            }
        }
    }
}
