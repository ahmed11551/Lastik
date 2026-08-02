<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Composite performance indexes (receipts = fiscal_receipts).
        if (Schema::hasTable('fiscal_receipts')) {
            Schema::table('fiscal_receipts', function (Blueprint $table): void {
                if (! $this->hasIndex('fiscal_receipts', 'fiscal_receipts_tenant_created_status_idx')) {
                    $table->index(['tenant_id', 'created_at', 'status'], 'fiscal_receipts_tenant_created_status_idx');
                }
            });
        }

        if (Schema::hasTable('stock_batches')) {
            Schema::table('stock_batches', function (Blueprint $table): void {
                // Spec alias remaining_quantity → remaining_qty
                if (! $this->hasIndex('stock_batches', 'stock_batches_tenant_product_wh_remaining_idx')) {
                    $table->index(
                        ['tenant_id', 'product_id', 'warehouse_id', 'remaining_qty'],
                        'stock_batches_tenant_product_wh_remaining_idx',
                    );
                }
            });
        }

        if (Schema::hasTable('loyalty_transactions')) {
            Schema::table('loyalty_transactions', function (Blueprint $table): void {
                if (! $this->hasIndex('loyalty_transactions', 'loyalty_transactions_tenant_customer_idx')) {
                    $table->index(['tenant_id', 'customer_id'], 'loyalty_transactions_tenant_customer_idx');
                }
            });
        }

        // Seal RLS for post-000038 tenant tables (FORCE + policy).
        if (DB::getDriverName() === 'pgsql') {
            $tables = [
                'fiscal_receipts',
                'stock_batches',
                'stock_lot_deductions',
                'loyalty_transactions',
                'refunds',
                'refund_items',
                'marking_validations',
                'inventory_alerts',
            ];

            foreach ($tables as $table) {
                if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'tenant_id')) {
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
    }

    public function down(): void
    {
        if (Schema::hasTable('fiscal_receipts') && $this->hasIndex('fiscal_receipts', 'fiscal_receipts_tenant_created_status_idx')) {
            Schema::table('fiscal_receipts', function (Blueprint $table): void {
                $table->dropIndex('fiscal_receipts_tenant_created_status_idx');
            });
        }

        if (Schema::hasTable('stock_batches') && $this->hasIndex('stock_batches', 'stock_batches_tenant_product_wh_remaining_idx')) {
            Schema::table('stock_batches', function (Blueprint $table): void {
                $table->dropIndex('stock_batches_tenant_product_wh_remaining_idx');
            });
        }

        // loyalty index may pre-exist from 000014 — only drop if we created the named one
        if (Schema::hasTable('loyalty_transactions') && $this->hasIndex('loyalty_transactions', 'loyalty_transactions_tenant_customer_idx')) {
            // Keep if from 000014 with different name; safe drop of our name only.
            try {
                Schema::table('loyalty_transactions', function (Blueprint $table): void {
                    $table->dropIndex('loyalty_transactions_tenant_customer_idx');
                });
            } catch (\Throwable) {
                // ignore
            }
        }
    }

    private function hasIndex(string $table, string $index): bool
    {
        if (DB::getDriverName() === 'pgsql') {
            $row = DB::selectOne(
                'SELECT 1 AS ok FROM pg_indexes WHERE tablename = ? AND indexname = ?',
                [$table, $index],
            );

            return $row !== null;
        }

        return Schema::hasIndex($table, $index);
    }
};
