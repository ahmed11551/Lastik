<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\ProductService;
use App\Models\Tenant;
use App\Models\Warehouse;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductServiceFactory extends Factory
{
    protected $model = ProductService::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'warehouse_id' => Warehouse::factory(),
            'type' => fake()->randomElement(['product', 'service']),
            'name' => fake('ru_RU')->words(3, true),
            'sku' => fake()->unique()->bothify('???-#####'),
            'unit' => fake()->randomElement(['шт', 'л', 'кг', 'усл']),
            'cost_price' => fake()->randomFloat(2, 100, 1000),
            'selling_price' => fake()->randomFloat(2, 150, 1500),
            'vat_rate' => fake()->randomElement([0, 10, 20]),
            'is_active' => true,
        ];
    }
}
