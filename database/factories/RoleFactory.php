<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Role;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class RoleFactory extends Factory
{
    protected $model = Role::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake('ru_RU')->jobTitle(),
            'slug' => fake()->unique()->slug(),
        ];
    }
}
