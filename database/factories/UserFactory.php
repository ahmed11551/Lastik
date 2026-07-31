<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Location;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class UserFactory extends Factory
{
    protected $model = User::class;

    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'location_id' => Location::factory(),
            'role_id' => Role::factory(),
            'name' => fake('ru_RU')->name(),
            'email' => fake('ru_RU')->unique()->safeEmail(),
            'phone' => fake('ru_RU')->phoneNumber(),
            'telegram_id' => null,
            'two_factor_method' => null,
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ];
    }
}
