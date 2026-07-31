<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 */

declare(strict_types=1);

namespace Database\Factories;

use Autometria\Models\Customer;
use Autometria\Models\Tenant;
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
