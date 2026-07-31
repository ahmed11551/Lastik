<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 */

declare(strict_types=1);

namespace Tests\Support;

use Autometria\Models\CashShift;
use Autometria\Models\Customer;
use Autometria\Models\KpiRule;
use Autometria\Models\Location;
use Autometria\Models\Module;
use Autometria\Models\Price;
use Autometria\Models\ProductService;
use Autometria\Models\Role;
use Autometria\Models\Stock;
use Autometria\Models\Tenant;
use Autometria\Models\User;
use Autometria\Models\Vehicle;
use Autometria\Models\Warehouse;
use Autometria\Services\DictionaryService;
use Illuminate\Support\Facades\Hash;

final class AcceptanceFixture
{
    public Tenant $tenant;

    public Location $location;

    public Role $role;

    public User $user;

    public User $master;

    public Customer $customer;

    public Vehicle $vehicle;

    public Warehouse $warehouse;

    public ProductService $product;

    public ProductService $service;

    public Stock $stock;

    public CashShift $shift;

    public Module $module;

    public static function make(string $suffix = ''): self
    {
        $fx = new self;
        $suffix = $suffix !== '' ? $suffix : (string) hrtime(true);

        $fx->tenant = Tenant::query()->create([
            'name' => 'Accept Tenant '.$suffix,
            'slug' => 'accept-'.$suffix,
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        set_current_tenant_id($fx->tenant->id);

        $fx->location = Location::query()->withoutGlobalScopes()->create([
            'tenant_id' => $fx->tenant->id,
            'name' => 'Точка А',
            'address' => 'Тест',
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ]);

        $fx->role = Role::query()->withoutGlobalScopes()->create([
            'tenant_id' => $fx->tenant->id,
            'name' => 'Админ',
            'slug' => 'admin',
            'permissions' => [
                'orders.create', 'orders.view', 'orders.update', 'orders.cancel',
                'payments.create', 'payments.correct',
                'shifts.create', 'shifts.close',
                'products.view', 'modules.view', 'modules.update',
                'price.change', 'discount.apply',
                'customers.view', 'customers.create', 'customers.update',
                'stock.view', 'stock.transfer', 'stock.import',
                'settings.view', 'settings.update',
                'locations.all', 'admin.dashboard',
            ],
        ]);

        $fx->user = User::query()->create([
            'tenant_id' => $fx->tenant->id,
            'location_id' => $fx->location->id,
            'role_id' => $fx->role->id,
            'name' => 'Кассир Тест',
            'email' => 'user-'.$suffix.'@lastik.local',
            'phone' => '+7900'.substr($suffix, -7),
            'password_hash' => Hash::make('password'),
            'devices_limit' => 2,
            'is_active' => true,
        ]);

        $fx->master = User::query()->create([
            'tenant_id' => $fx->tenant->id,
            'location_id' => $fx->location->id,
            'role_id' => $fx->role->id,
            'name' => 'Мастер Тест',
            'email' => 'master-'.$suffix.'@lastik.local',
            'phone' => '+7901'.substr($suffix, -7),
            'password_hash' => Hash::make('password'),
            'devices_limit' => 2,
            'is_active' => true,
        ]);

        $fx->customer = Customer::query()->withoutGlobalScopes()->create([
            'tenant_id' => $fx->tenant->id,
            'type' => Customer::TYPE_INDIVIDUAL,
            'name' => 'Иван Клиент '.substr($suffix, -6),
            'legal_name' => 'Иван Клиент '.substr($suffix, -6),
            'phone' => '+7900'.str_pad((string) (abs(crc32($suffix)) % 10000000), 7, '0', STR_PAD_LEFT),
            'email' => 'client-'.$suffix.'@ex.com',
        ]);

        $fx->vehicle = Vehicle::query()->withoutGlobalScopes()->create([
            'tenant_id' => $fx->tenant->id,
            'customer_id' => $fx->customer->id,
            'plate' => 'A'.substr($suffix, -3).'BC77',
            'brand' => 'Toyota',
            'model' => 'Camry',
        ]);

        $fx->warehouse = Warehouse::query()->withoutGlobalScopes()->create([
            'tenant_id' => $fx->tenant->id,
            'location_id' => $fx->location->id,
            'name' => 'Склад А',
            'location' => 'A',
        ]);

        $fx->product = ProductService::query()->withoutGlobalScopes()->create([
            'tenant_id' => $fx->tenant->id,
            'type' => ProductService::TYPE_PRODUCT,
            'article' => 'T-'.$suffix,
            'external_id' => '1C-'.$suffix,
            'name' => 'Шина тест',
            'brand' => 'Michelin',
            'unit' => 'шт',
            'is_active' => true,
            'base_price' => 5000,
        ]);

        $fx->service = ProductService::query()->withoutGlobalScopes()->create([
            'tenant_id' => $fx->tenant->id,
            'type' => ProductService::TYPE_SERVICE,
            'article' => 'S-'.$suffix,
            'external_id' => '1CS-'.$suffix,
            'name' => 'Шиномонтаж',
            'unit' => 'усл',
            'is_active' => true,
            'base_price' => 1200,
            'category' => 'mounting',
        ]);

        Price::query()->withoutGlobalScopes()->create([
            'tenant_id' => $fx->tenant->id,
            'product_id' => $fx->product->id,
            'type' => 'retail',
            'price' => 5000,
            'cost_price' => 3500,
            'amount' => 5000,
        ]);

        Price::query()->withoutGlobalScopes()->create([
            'tenant_id' => $fx->tenant->id,
            'product_id' => $fx->service->id,
            'type' => 'retail',
            'price' => 1200,
            'cost_price' => null,
            'amount' => 1200,
        ]);

        $fx->stock = Stock::query()->withoutGlobalScopes()->create([
            'tenant_id' => $fx->tenant->id,
            'warehouse_id' => $fx->warehouse->id,
            'product_id' => $fx->product->id,
            'actual' => 20,
            'reserved' => 0,
            'available' => 20,
        ]);

        KpiRule::query()->withoutGlobalScopes()->create([
            'tenant_id' => $fx->tenant->id,
            'product_id' => $fx->product->id,
            'applies_to' => 'product',
            'target_type' => 'seller',
            'percent' => 5,
            'is_active' => true,
        ]);

        KpiRule::query()->withoutGlobalScopes()->create([
            'tenant_id' => $fx->tenant->id,
            'product_id' => $fx->service->id,
            'applies_to' => 'service',
            'target_type' => 'master',
            'percent' => 15,
            'is_active' => true,
        ]);

        $fx->shift = CashShift::query()->withoutGlobalScopes()->create([
            'tenant_id' => $fx->tenant->id,
            'location_id' => $fx->location->id,
            'user_id' => $fx->user->id,
            'opened_by' => $fx->user->id,
            'status' => 'opened',
            'opening_amount' => 1000,
            'opened_at' => now(),
            'closed_at' => null,
            'totals' => ['cash' => 0, 'card' => 0, 'transfer' => 0],
        ]);

        $fx->module = Module::query()->withoutGlobalScopes()->create([
            'tenant_id' => $fx->tenant->id,
            'slug' => 'demo_module',
            'status' => Module::STATUS_AVAILABLE,
            'settings' => [
                'menu' => ['label' => 'Demo', 'route' => '/demo'],
                'permissions' => ['demo.view'],
            ],
        ]);

        app(DictionaryService::class)->seedDefaults($fx->tenant->id);

        return $fx;
    }
}
