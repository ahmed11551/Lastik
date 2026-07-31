<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TENANT_TABLES = [
        'locations',
        'users',
        'roles',
        'permissions',
        'customers',
        'vehicles',
        'products_services',
        'warehouses',
        'stocks',
        'prices',
        'orders',
        'order_items',
        'reservations',
        'payments',
        'payment_corrections',
        'issuances',
        'cash_shifts',
        'cash_movements',
        'kpi_rules',
        'earnings',
        'audit_logs',
        'import_jobs',
        'modules',
        'settings',
        'stock_conflicts',
        'devices',
        'login_histories',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (self::TENANT_TABLES as $table) {
            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("CREATE POLICY tenant_isolation_{$table} ON {$table} USING (tenant_id = current_setting('app.current_tenant_id')::bigint)");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach (array_reverse(self::TENANT_TABLES) as $table) {
            DB::statement("DROP POLICY IF EXISTS tenant_isolation_{$table} ON {$table}");
        }
    }
};
