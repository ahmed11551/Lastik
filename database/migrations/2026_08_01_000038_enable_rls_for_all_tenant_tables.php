<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Повторное включение RLS (+ FORCE) после создания всех доменных таблиц.
 * Миграция 000004 запускается слишком рано и пропускает поздние таблицы.
 * Таблица tenants намеренно исключена.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $tables = [
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
            'order_item_workers',
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
            'stock_transfers',
            'devices',
            'login_histories',
            'dictionaries',
            'tasks',
            'money_recipients',
            'customer_merges',
            'posts',
            'bookings',
        ];

        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            if (! Schema::hasColumn($table, 'tenant_id')) {
                continue;
            }

            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
            DB::statement("DROP POLICY IF EXISTS tenant_isolation_{$table} ON {$table}");
            DB::statement(
                "CREATE POLICY tenant_isolation_{$table} ON {$table}
                 USING (tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::bigint)"
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $tables = [
            'bookings', 'posts', 'customer_merges', 'money_recipients', 'tasks', 'dictionaries',
            'login_histories', 'devices', 'stock_transfers', 'stock_conflicts', 'settings', 'modules',
            'import_jobs', 'audit_logs', 'earnings', 'kpi_rules', 'cash_movements', 'cash_shifts',
            'issuances', 'payment_corrections', 'payments', 'reservations', 'order_item_workers',
            'order_items', 'orders', 'prices', 'stocks', 'warehouses', 'products_services',
            'vehicles', 'customers', 'permissions', 'roles', 'users', 'locations',
        ];

        foreach ($tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }

            DB::statement("DROP POLICY IF EXISTS tenant_isolation_{$table} ON {$table}");
            DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} DISABLE ROW LEVEL SECURITY");
        }
    }
};
