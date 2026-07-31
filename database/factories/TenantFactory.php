<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    public function definition(): array
    {
        return [
            'name' => fake('ru_RU')->company().' Шиномонтаж',
            'slug' => fake()->unique()->slug(),
            'timezone' => 'Europe/Moscow',
            'is_active' => true,
        ];
    }
}
