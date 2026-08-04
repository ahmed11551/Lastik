<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 *
 * Gate P0 / Hermes — RLS для fiscal_receipts и всего оставшегося хвоста tenant-таблиц.
 * Регламент: ENABLE + FORCE ROW LEVEL SECURITY, политика tenant_isolation_*
 * с USING и WITH CHECK на app.current_tenant_id.
 * Идемпотентно (DROP POLICY IF EXISTS) — безопасно при параллельном прогоне
 * с 2026_08_03_000040_enforce_rls_all_remaining_tables.php.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Таблицы из регламента Gate P0 (финансовые/складские/маркировочные + категории).
     * Остальные tenant-таблицы подхватываются динамическим сканированием ниже.
     *
     * @var list<string>
     */
    private array $explicitTables = [
        'fiscal_receipts',
        'stock_batches',
        'lot_deductions',
        'refunds',
        'refund_items',
        'marking_codes',
        'marking_validations',
        'loyalty_transactions',
        'inventory_documents',
        'inventory_document_items',
        'categories',
        'product_variants',
        'one_c_sync_logs',
    ];

    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $tables = $this->resolveTables();

        foreach ($tables as $table) {
            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'tenant_id')) {
                continue;
            }

            $setting = "NULLIF(current_setting('app.current_tenant_id', true), '')::bigint";

            DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");

            // Убираем оба исторических имени политик, затем создаём единую.
            DB::statement("DROP POLICY IF EXISTS tenant_isolation_{$table} ON {$table}");
            DB::statement("DROP POLICY IF EXISTS {$table}_tenant_isolation ON {$table}");

            DB::statement(
                "CREATE POLICY tenant_isolation_{$table} ON {$table}
                 FOR ALL
                 TO PUBLIC
                 USING (tenant_id = {$setting})
                 WITH CHECK (tenant_id = {$setting})"
            );
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        foreach ($this->resolveTables() as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            DB::statement("DROP POLICY IF EXISTS tenant_isolation_{$table} ON {$table}");
            DB::statement("DROP POLICY IF EXISTS {$table}_tenant_isolation ON {$table}");
            DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
        }
    }

    /**
     * @return list<string>
     */
    private function resolveTables(): array
    {
        $discovered = [];

        $rows = DB::select(
            "SELECT c.relname AS table_name
             FROM pg_class c
             JOIN pg_namespace n ON n.oid = c.relnamespace
             WHERE n.nspname = current_schema()
               AND c.relkind = 'r'
               AND c.relname <> 'tenants'
               AND EXISTS (
                   SELECT 1 FROM information_schema.columns col
                   WHERE col.table_schema = current_schema()
                     AND col.table_name = c.relname
                     AND col.column_name = 'tenant_id'
               )
             ORDER BY c.relname"
        );

        foreach ($rows as $row) {
            $discovered[] = (string) $row->table_name;
        }

        return array_values(array_unique([...$this->explicitTables, ...$discovered]));
    }
};
