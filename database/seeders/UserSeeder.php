<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 */

declare(strict_types=1);

namespace Database\Seeders;

use Autometria\Models\Location;
use Autometria\Models\Role;
use Autometria\Models\Tenant;
use Autometria\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::where('slug', 'demo')->firstOrFail();
        $location = Location::where('tenant_id', $tenant->id)->firstOrFail();
        $adminRole = Role::where('tenant_id', $tenant->id)->where('slug', 'admin')->firstOrFail();

        User::updateOrCreate(
            ['email' => 'admin@lastik.local'],
            [
                'tenant_id' => $tenant->id,
                'location_id' => $location->id,
                'role_id' => $adminRole->id,
                'name' => 'Admin',
                'password' => Hash::make('password'),
                'devices_limit' => 2,
            ]
        );
    }
}
