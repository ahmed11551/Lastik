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
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class LocationSeeder extends Seeder
{
    public function run(): void
    {
        $tenants = DB::table('tenants')->get(['id'])->pluck('id');

        foreach ($tenants as $tenantId) {
            Location::updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'name' => 'Main Location',
                ],
                [
                    'tenant_id' => $tenantId,
                    'name' => 'Main Location',
                    'timezone' => 'UTC',
                    'is_active' => true,
                ]
            );
        }
    }
}
