<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 *
 * DemoSeeder — изолированный демо-тенант для инвесторского показа и пилотов.
 * Запуск: php artisan db:seed --class=DemoSeeder
 * Создаёт: 1 СТО «АвтоДемо», 1 точку, 1 склад, ячейки хранения (WMS Light),
 * 8 заказов в разных статусах (очередь / в работе / готово) для ТВ-борда.
 * Логин: admin@demo.local / password
 */

declare(strict_types=1);

namespace Database\Seeders;

use Autometria\Models\Location;
use Autometria\Models\Order;
use Autometria\Models\ProductService;
use Autometria\Models\Role;
use Autometria\Models\Stock;
use Autometria\Models\StorageCell;
use Autometria\Models\Tenant;
use Autometria\Models\User;
use Autometria\Models\Vehicle;
use Autometria\Models\Warehouse;
use Autometria\Support\helpers;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final class DemoSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $tenant = Tenant::query()->withoutGlobalScopes()->updateOrCreate(
                ['slug' => 'demo'],
                [
                    'name' => 'АвтоДемо (демо СТО)',
                    'timezone' => 'Europe/Moscow',
                    'is_active' => true,
                ]
            );

            set_current_tenant_id($tenant->id);

            $adminRole = Role::query()->withoutGlobalScopes()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'slug' => 'admin'],
                [
                    'tenant_id' => $tenant->id,
                    'name' => 'Админ',
                    'slug' => 'admin',
                    'permissions' => ['*'],
                ]
            );

            $warehouseRole = Role::query()->withoutGlobalScopes()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'slug' => 'warehouse'],
                [
                    'tenant_id' => $tenant->id,
                    'name' => 'Кладовщик',
                    'slug' => 'warehouse',
                    'permissions' => ['stock.view', 'stock.transfer', 'stock.import'],
                ]
            );

            $location = Location::query()->withoutGlobalScopes()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'name' => 'СТО Центр'],
                [
                    'tenant_id' => $tenant->id,
                    'name' => 'СТО Центр',
                    'address' => 'г. Москва, ул. Демонстрационная, 1',
                    'timezone' => 'Europe/Moscow',
                    'is_active' => true,
                ]
            );

            $warehouse = Warehouse::query()->withoutGlobalScopes()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'name' => 'Склад А'],
                [
                    'tenant_id' => $tenant->id,
                    'name' => 'Склад А',
                    'location_id' => $location->id,
                ]
            );

            // Ячейки хранения (WMS Light).
            foreach (['A-01', 'A-02', 'B-01', 'B-02'] as $code) {
                StorageCell::query()->withoutGlobalScopes()->updateOrCreate(
                    ['tenant_id' => $tenant->id, 'code' => $code],
                    [
                        'tenant_id' => $tenant->id,
                        'warehouse_id' => $warehouse->id,
                        'code' => $code,
                        'zone' => substr($code, 0, 1),
                        'is_active' => true,
                    ]
                );
            }

            $password = Hash::make('password');

            $admin = User::query()->withoutGlobalScopes()->updateOrCreate(
                ['email' => 'admin@demo.local'],
                [
                    'tenant_id' => $tenant->id,
                    'location_id' => $location->id,
                    'role_id' => $adminRole->id,
                    'name' => 'Демо Админ',
                    'email' => 'admin@demo.local',
                    'phone' => '+79000000001',
                    'password_hash' => $password,
                    'devices_limit' => 5,
                    'is_active' => true,
                ]
            );

            // Базовые товары/услуги для заказов.
            $service = ProductService::query()->withoutGlobalScopes()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'name' => 'Замена масла'],
                [
                    'tenant_id' => $tenant->id,
                    'type' => 'service',
                    'category' => 'maintenance',
                    'unit' => 'pcs',
                    'base_price' => 1500,
                    'is_active' => true,
                ]
            );

            $goods = ProductService::query()->withoutGlobalScopes()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'name' => 'Масло 5W40'],
                [
                    'tenant_id' => $tenant->id,
                    'type' => 'product',
                    'category' => 'consumable',
                    'unit' => 'pcs',
                    'base_price' => 800,
                    'is_active' => true,
                ]
            );

            // Остаток на складе (для демонстрации склада хранения).
            Stock::query()->withoutGlobalScopes()->updateOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $goods->id,
                ],
                [
                    'tenant_id' => $tenant->id,
                    'warehouse_id' => $warehouse->id,
                    'product_id' => $goods->id,
                    'actual' => 50,
                    'reserved' => 0,
                    'available' => 50,
                ]
            );

            // 8 заказов в разных статусах для ТВ-борда.
            $statuses = [
                Order::STATUS_CREATED,
                Order::STATUS_CREATED,
                Order::STATUS_CREATED,
                Order::STATUS_IN_PROGRESS,
                Order::STATUS_IN_PROGRESS,
                Order::STATUS_IN_PROGRESS,
                Order::STATUS_READY,
                Order::STATUS_ISSUED,
            ];

            $plates = ['А001АА', 'В002ВВ', 'С003СС', 'У004УУ', 'К005КК', 'М006ММ', 'Н007НН', 'Т008ТТ'];

            foreach ($statuses as $i => $status) {
                $vehicle = Vehicle::query()->withoutGlobalScopes()->updateOrCreate(
                    ['tenant_id' => $tenant->id, 'plate' => $plates[$i]],
                    [
                        'tenant_id' => $tenant->id,
                        'plate' => $plates[$i],
                        'brand' => 'Lada',
                        'model' => 'Vesta',
                    ]
                );

                Order::query()->withoutGlobalScopes()->forceCreate([
                    'tenant_id' => $tenant->id,
                    'location_id' => $location->id,
                    'customer_id' => null,
                    'vehicle_id' => $vehicle->id,
                    'status' => $status,
                    'number' => 'DEMO-' . str_pad((string) ($i + 1), 3, '0'),
                    'scenario' => 'with_installation',
                    'total' => 2300,
                    'created_by' => $admin->id,
                ]);
            }

            $this->command?->info('DemoSeeder OK — login: admin@demo.local / password (tenant: demo)');
        });
    }
}
