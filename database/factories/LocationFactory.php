<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Location;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class LocationFactory extends Factory
{
    protected $model = Location::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake('ru_RU')->city(),
            'address' => fake('ru_RU')->address(),
        ];
    }
}
