<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Models\Tenant;
use Illuminate\Database\Seeder;

class TenantSeeder extends Seeder
{
    public function run(): void
    {
        Tenant::updateOrCreate(['slug' => 'demo'], [
            'name' => 'Демо шиномонтаж',
            'slug' => 'demo',
            'plan' => 'trial',
        ]);
    }
}
