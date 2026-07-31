<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Customer;
use App\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'name' => fake('ru_RU')->name(),
            'phone' => fake('ru_RU')->phoneNumber(),
            'email' => fake('ru_RU')->optional()->safeEmail(),
            'notes' => fake('ru_RU')->sentence(),
        ];
    }
}
