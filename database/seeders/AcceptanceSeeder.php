<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\CashShift;
use App\Models\Customer;
use App\Models\KpiRule;
use App\Models\Location;
use App\Models\Module;
use App\Models\Order;
use App\Models\Permission;
use App\Models\Price;
use App\Models\ProductService;
use App\Models\Role;
use App\Models\Stock;
use App\Models\Tenant;
use App\Models\User;
use App\Models\Vehicle;
use App\Models\Warehouse;
use App\Services\DictionaryService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Полное окружение приёмки по п. 45 ТЗ:
 * 1 организация, 2 точки, 2 склада, ≥5 пользователей с ролями,
 * 3 физлица, 2 юрлица, 3 авто, 10 товаров, 5 услуг, остатки, цены, открытая смена.
 */
final class AcceptanceSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function (): void {
            $tenant = Tenant::query()->withoutGlobalScopes()->updateOrCreate(
                ['slug' => 'acceptance'],
                [
                    'name' => 'Приёмочный шинный центр',
                    'timezone' => 'Europe/Moscow',
                    'is_active' => true,
                ]
            );

            app()->instance('current_tenant_id', $tenant->id);

            foreach (PermissionSeeder::PERMISSIONS as $permission) {
                Permission::query()->withoutGlobalScopes()->updateOrCreate(
                    [
                        'tenant_id' => $tenant->id,
                        'slug' => $permission['slug'],
                    ],
                    [
                        'tenant_id' => $tenant->id,
                        'name' => $permission['slug'],
                        'slug' => $permission['slug'],
                        'section' => $permission['section'],
                        'action' => $permission['action'],
                    ]
                );
            }

            $allSlugs = array_column(PermissionSeeder::PERMISSIONS, 'slug');

            $roles = [
                'owner' => ['name' => 'Суперадмин организации', 'permissions' => $allSlugs],
                'admin' => ['name' => 'Админ', 'permissions' => $allSlugs],
                'seller' => ['name' => 'Продавец / менеджер', 'permissions' => [
                    'orders.view', 'orders.create', 'orders.update',
                    'customers.view', 'customers.create', 'customers.update',
                    'vehicles.view', 'vehicles.create', 'vehicles.update',
                    'products.view',
                ]],
                'cashier' => ['name' => 'Кассир', 'permissions' => [
                    'orders.view', 'payments.create', 'payments.correct',
                    'shifts.create', 'shifts.close',
                ]],
                'master' => ['name' => 'Мастер / исполнитель', 'permissions' => [
                    'orders.view', 'orders.update', 'products.view',
                ]],
                'warehouse_manager' => ['name' => 'Кладовщик', 'permissions' => [
                    'stock.transfer', 'stock.import',
                    'warehouses.view', 'warehouses.create', 'warehouses.update',
                    'products.view', 'products.create', 'products.update',
                ]],
            ];

            $roleModels = [];
            foreach ($roles as $slug => $data) {
                $roleModels[$slug] = Role::query()->withoutGlobalScopes()->updateOrCreate(
                    ['tenant_id' => $tenant->id, 'slug' => $slug],
                    [
                        'tenant_id' => $tenant->id,
                        'name' => $data['name'],
                        'slug' => $slug,
                        'permissions' => $data['permissions'],
                    ]
                );
            }

            $pointA = Location::query()->withoutGlobalScopes()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'name' => 'Точка Север'],
                [
                    'tenant_id' => $tenant->id,
                    'name' => 'Точка Север',
                    'address' => 'г. Москва, ул. Северная, 1',
                    'timezone' => 'Europe/Moscow',
                    'is_active' => true,
                ]
            );

            $pointB = Location::query()->withoutGlobalScopes()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'name' => 'Точка Юг'],
                [
                    'tenant_id' => $tenant->id,
                    'name' => 'Точка Юг',
                    'address' => 'г. Москва, ул. Южная, 2',
                    'timezone' => 'Europe/Moscow',
                    'is_active' => true,
                ]
            );

            $password = Hash::make('password');

            $usersSpec = [
                ['email' => 'owner@lastik.local', 'name' => 'Суперадмин Иванов', 'role' => 'owner', 'location' => $pointA],
                ['email' => 'admin@lastik.local', 'name' => 'Админ Петров', 'role' => 'admin', 'location' => $pointA],
                ['email' => 'seller@lastik.local', 'name' => 'Продавец Сидоров', 'role' => 'seller', 'location' => $pointA],
                ['email' => 'cashier@lastik.local', 'name' => 'Кассир Козлова', 'role' => 'cashier', 'location' => $pointA],
                ['email' => 'master@lastik.local', 'name' => 'Мастер Орлов', 'role' => 'master', 'location' => $pointA],
                ['email' => 'warehouse@lastik.local', 'name' => 'Кладовщик Волков', 'role' => 'warehouse_manager', 'location' => $pointB],
            ];

            $users = [];
            foreach ($usersSpec as $spec) {
                $users[$spec['role']] = User::query()->withoutGlobalScopes()->updateOrCreate(
                    ['email' => $spec['email']],
                    [
                        'tenant_id' => $tenant->id,
                        'location_id' => $spec['location']->id,
                        'role_id' => $roleModels[$spec['role']]->id,
                        'name' => $spec['name'],
                        'email' => $spec['email'],
                        'phone' => '+7900'.str_pad((string) random_int(1000000, 9999999), 7, '0'),
                        'password_hash' => $password,
                        'devices_limit' => 2,
                        'is_active' => true,
                    ]
                );
            }

            $whA = Warehouse::query()->withoutGlobalScopes()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'name' => 'Склад Север'],
                [
                    'tenant_id' => $tenant->id,
                    'location_id' => $pointA->id,
                    'name' => 'Склад Север',
                    'location' => 'Точка Север',
                ]
            );

            $whB = Warehouse::query()->withoutGlobalScopes()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'name' => 'Склад Юг'],
                [
                    'tenant_id' => $tenant->id,
                    'location_id' => $pointB->id,
                    'name' => 'Склад Юг',
                    'location' => 'Точка Юг',
                ]
            );

            $individuals = [
                ['name' => 'Алексей Клиент', 'phone' => '+79001112233', 'email' => 'alex@example.com'],
                ['name' => 'Мария Покупатель', 'phone' => '+79004445566', 'email' => 'maria@example.com'],
                ['name' => 'Игорь Сервис', 'phone' => '+79007778899', 'email' => 'igor@example.com'],
            ];

            $customers = [];
            foreach ($individuals as $row) {
                $customers[] = Customer::query()->withoutGlobalScopes()->updateOrCreate(
                    ['tenant_id' => $tenant->id, 'phone' => $row['phone']],
                    [
                        'tenant_id' => $tenant->id,
                        'type' => Customer::TYPE_INDIVIDUAL,
                        'name' => $row['name'],
                        'legal_name' => $row['name'],
                        'phone' => $row['phone'],
                        'email' => $row['email'],
                    ]
                );
            }

            $legals = [
                ['legal_name' => 'ООО Ромашка', 'inn' => '7701234567', 'kpp' => '770101001', 'phone' => '+74951234567'],
                ['legal_name' => 'ИП Смирнов', 'inn' => '500123456789', 'kpp' => null, 'phone' => '+74957654321'],
            ];

            foreach ($legals as $row) {
                $customers[] = Customer::query()->withoutGlobalScopes()->updateOrCreate(
                    ['tenant_id' => $tenant->id, 'inn' => $row['inn']],
                    [
                        'tenant_id' => $tenant->id,
                        'type' => Customer::TYPE_LEGAL,
                        'name' => $row['legal_name'],
                        'legal_name' => $row['legal_name'],
                        'inn' => $row['inn'],
                        'kpp' => $row['kpp'],
                        'phone' => $row['phone'],
                    ]
                );
            }

            $vehicles = [
                ['plate' => 'A123BC77', 'brand' => 'Toyota', 'model' => 'Camry', 'customer' => 0],
                ['plate' => 'B456DE199', 'brand' => 'Kia', 'model' => 'Rio', 'customer' => 1],
                ['plate' => 'C789FG777', 'brand' => 'BMW', 'model' => 'X5', 'customer' => 2],
            ];

            $vehicleModels = [];
            foreach ($vehicles as $row) {
                $vehicleModels[] = Vehicle::query()->withoutGlobalScopes()->updateOrCreate(
                    ['tenant_id' => $tenant->id, 'plate' => $row['plate']],
                    [
                        'tenant_id' => $tenant->id,
                        'customer_id' => $customers[$row['customer']]->id,
                        'plate' => $row['plate'],
                        'brand' => $row['brand'],
                        'model' => $row['model'],
                        'vin' => strtoupper(substr(md5($row['plate']), 0, 17)),
                    ]
                );
            }

            $products = [];
            for ($i = 1; $i <= 10; $i++) {
                $article = sprintf('TIRE-%03d', $i);
                $product = ProductService::query()->withoutGlobalScopes()->updateOrCreate(
                    ['tenant_id' => $tenant->id, 'article' => $article],
                    [
                        'tenant_id' => $tenant->id,
                        'type' => ProductService::TYPE_PRODUCT,
                        'article' => $article,
                        'external_id' => '1C-TIRE-'.$i,
                        'name' => "Шина тестовая {$i} 205/55 R16",
                        'brand' => $i % 2 === 0 ? 'Michelin' : 'Continental',
                        'unit' => 'шт',
                        'is_active' => true,
                        'base_price' => 5000 + ($i * 100),
                    ]
                );
                $products[] = $product;

                $amount = 5000 + ($i * 100);
                Price::query()->withoutGlobalScopes()->updateOrCreate(
                    ['tenant_id' => $tenant->id, 'product_id' => $product->id],
                    [
                        'tenant_id' => $tenant->id,
                        'product_id' => $product->id,
                        'type' => 'retail',
                        'price' => $amount,
                        'cost_price' => 3500 + ($i * 50),
                        'amount' => $amount,
                    ]
                );

                foreach ([$whA, $whB] as $wh) {
                    $actual = 20 + $i;
                    Stock::query()->withoutGlobalScopes()->updateOrCreate(
                        [
                            'tenant_id' => $tenant->id,
                            'warehouse_id' => $wh->id,
                            'product_id' => $product->id,
                        ],
                        [
                            'tenant_id' => $tenant->id,
                            'warehouse_id' => $wh->id,
                            'product_id' => $product->id,
                            'actual' => $actual,
                            'reserved' => 0,
                            'available' => $actual,
                        ]
                    );
                }
            }

            for ($i = 1; $i <= 5; $i++) {
                $article = sprintf('SRV-%03d', $i);
                $service = ProductService::query()->withoutGlobalScopes()->updateOrCreate(
                    ['tenant_id' => $tenant->id, 'article' => $article],
                    [
                        'tenant_id' => $tenant->id,
                        'type' => ProductService::TYPE_SERVICE,
                        'article' => $article,
                        'external_id' => '1C-SRV-'.$i,
                        'name' => match ($i) {
                            1 => 'Шиномонтаж R16',
                            2 => 'Балансировка',
                            3 => 'Сезонное хранение',
                            4 => 'Ремонт прокола',
                            default => 'Диагностика ходовой',
                        },
                        'unit' => 'усл',
                        'is_active' => true,
                        'category' => 'mounting',
                        'base_price' => 800 + ($i * 200),
                        'radius_modifier' => ['R15' => 0, 'R16' => 100, 'R17' => 200],
                    ]
                );

                $amount = 800 + ($i * 200);
                Price::query()->withoutGlobalScopes()->updateOrCreate(
                    ['tenant_id' => $tenant->id, 'product_id' => $service->id],
                    [
                        'tenant_id' => $tenant->id,
                        'product_id' => $service->id,
                        'type' => 'retail',
                        'price' => $amount,
                        'cost_price' => null,
                        'amount' => $amount,
                    ]
                );

                KpiRule::query()->withoutGlobalScopes()->updateOrCreate(
                    [
                        'tenant_id' => $tenant->id,
                        'product_id' => $service->id,
                        'applies_to' => 'service',
                    ],
                    [
                        'tenant_id' => $tenant->id,
                        'product_id' => $service->id,
                        'applies_to' => 'service',
                        'target_type' => 'master',
                        'percent' => 10 + $i,
                        'amount' => null,
                        'is_active' => true,
                    ]
                );
            }

            foreach (array_slice($products, 0, 3) as $idx => $product) {
                KpiRule::query()->withoutGlobalScopes()->updateOrCreate(
                    [
                        'tenant_id' => $tenant->id,
                        'product_id' => $product->id,
                        'applies_to' => 'product',
                    ],
                    [
                        'tenant_id' => $tenant->id,
                        'product_id' => $product->id,
                        'applies_to' => 'product',
                        'target_type' => 'seller',
                        'percent' => 3 + $idx,
                        'amount' => null,
                        'is_active' => true,
                    ]
                );
            }

            Module::query()->withoutGlobalScopes()->updateOrCreate(
                ['tenant_id' => $tenant->id, 'slug' => 'demo_module'],
                [
                    'tenant_id' => $tenant->id,
                    'slug' => 'demo_module',
                    'status' => Module::STATUS_ACTIVE,
                    'enabled_at' => now(),
                    'settings' => ['menu' => true, 'label' => 'Тестовый модуль'],
                ]
            );

            CashShift::query()->withoutGlobalScopes()->updateOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'location_id' => $pointA->id,
                    'status' => 'opened',
                ],
                [
                    'tenant_id' => $tenant->id,
                    'location_id' => $pointA->id,
                    'user_id' => $users['cashier']->id,
                    'opened_by' => $users['cashier']->id,
                    'status' => 'opened',
                    'opening_amount' => 5000,
                    'opened_at' => now(),
                    'closed_at' => null,
                    'totals' => null,
                    'note' => 'Смена для приёмки',
                ]
            );

            // Demo orders for TV board (п. 42): queue / in_progress / ready
            $tvSpecs = [
                ['number' => 'TV-QUEUE-1', 'status' => Order::STATUS_CREATED, 'vehicle' => 0, 'customer' => 0],
                ['number' => 'TV-WORK-1', 'status' => Order::STATUS_IN_PROGRESS, 'vehicle' => 1, 'customer' => 1],
                ['number' => 'TV-READY-1', 'status' => Order::STATUS_READY, 'vehicle' => 2, 'customer' => 2],
            ];
            foreach ($tvSpecs as $spec) {
                Order::query()->withoutGlobalScopes()->updateOrCreate(
                    ['tenant_id' => $tenant->id, 'number' => $spec['number']],
                    [
                        'tenant_id' => $tenant->id,
                        'location_id' => $pointA->id,
                        'customer_id' => $customers[$spec['customer']]->id,
                        'vehicle_id' => $vehicleModels[$spec['vehicle']]->id,
                        'scenario' => 'with_installation',
                        'number' => $spec['number'],
                        'status' => $spec['status'],
                        'payment_status' => 'unpaid',
                        'assigned_seller_id' => $users['seller']->id,
                        'master_id' => $users['master']->id,
                        'total' => 1200,
                        'created_by' => $users['seller']->id,
                    ]
                );
            }

            app(DictionaryService::class)->seedDefaults($tenant->id);

            $this->command?->info('AcceptanceSeeder OK — login: admin@lastik.local / password (tenant: acceptance)');
            $this->command?->info('Also: seller@lastik.local, cashier@lastik.local / password');
        });
    }
}
