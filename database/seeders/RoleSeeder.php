<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 */

declare(strict_types=1);

namespace Database\Seeders;

use Autometria\Models\Role;
use Illuminate\Database\Seeder;

final class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            [
                'slug' => 'admin',
                'name' => 'Admin',
                'permissions' => [
                    'orders.view', 'orders.create', 'orders.update', 'orders.cancel',
                    'payments.create', 'payments.correct',
                    'shifts.create', 'shifts.close',
                    'stock.transfer', 'stock.import', 'stock.view',
                    'customers.view', 'customers.create', 'customers.update',
                    'vehicles.view', 'vehicles.create', 'vehicles.update',
                    'warehouses.view', 'warehouses.create', 'warehouses.update',
                    'products.view', 'products.create', 'products.update',
                    'settings.view', 'settings.update',
                    'modules.view', 'modules.update',
                    'admin.dashboard',
                    'locations.all',
                    'users.view',
                    'users.create',
                    'users.update',
                ],
            ],
            [
                'slug' => 'seller',
                'name' => 'Seller',
                'permissions' => [
                    'orders.view', 'orders.create',
                    'customers.view', 'customers.create',
                    'vehicles.view', 'vehicles.create',
                ],
            ],
            [
                'slug' => 'cashier',
                'name' => 'Cashier',
                'permissions' => [
                    'orders.view',
                    'payments.create', 'payments.correct',
                    'shifts.create', 'shifts.close',
                ],
            ],
            [
                'slug' => 'warehouse_manager',
                'name' => 'Warehouse Manager',
                'permissions' => [
                    'stock.view', 'stock.transfer', 'stock.import',
                    'warehouses.view', 'warehouses.create', 'warehouses.update',
                    'products.view', 'products.create', 'products.update',
                ],
            ],
        ];

        foreach ($roles as $role) {
            Role::updateOrCreate(['slug' => $role['slug']], $role);
        }
    }
}
