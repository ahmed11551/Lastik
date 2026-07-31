<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

final class TelegramUserSeeder extends Seeder
{
    public function run(): void
    {
        $tenants = DB::table('tenants')->get(['id'])->pluck('id');

        foreach ($tenants as $tenantId) {
            $telegramRole = Role::firstOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'slug' => 'telegram',
                ],
                [
                    'tenant_id' => $tenantId,
                    'slug' => 'telegram',
                    'name' => 'Telegram Bot',
                    'permissions' => [],
                ]
            );

            User::updateOrCreate(
                [
                    'tenant_id' => $tenantId,
                    'email' => 'bot@telegram.local',
                ],
                [
                    'tenant_id' => $tenantId,
                    'location_id' => null,
                    'role_id' => $telegramRole->id,
                    'name' => 'Telegram Bot',
                    'email' => 'bot@telegram.local',
                    'password_hash' => '',
                    'two_factor_secret' => null,
                    'devices_limit' => 0,
                    'last_login_at' => now(),
                    'is_active' => true,
                ]
            );
        }
    }
}
