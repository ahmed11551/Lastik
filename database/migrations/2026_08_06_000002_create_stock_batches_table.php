<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 *
 * WMS 2.0 — stock_batches alignment (BIGINT tenant_id + RLS).
 * Legacy FIFO columns (qty / remaining_qty / warehouse_id / cost_price) are preserved
 * when the table already exists from 2026_08_02_000002.
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
        if (! Schema::hasTable('stock_batches')) {
            Schema::create('stock_batches', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('product_id');
                $table->unsignedBigInteger('warehouse_bin_id')->nullable();
                $table->string('batch_number', 100);
                $table->string('serial_number', 100)->nullable();
                $table->decimal('quantity', 15, 3)->default(0);
                $table->date('expiration_date')->nullable();
                $table->timestamp('received_at');
                $table->timestamps();

                $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
                // Catalog table in Autometria is products_services (not products).
                $table->foreign('product_id')->references('id')->on('products_services')->cascadeOnDelete();
                $table->foreign('warehouse_bin_id')->references('id')->on('warehouse_bins')->nullOnDelete();

                $table->index(['tenant_id', 'product_id', 'expiration_date'], 'stock_batches_wms_fefo_idx');
                $table->index(['tenant_id', 'warehouse_bin_id'], 'stock_batches_wms_bin_idx');
            });
        } else {
            Schema::table('stock_batches', function (Blueprint $table): void {
                if (! Schema::hasColumn('stock_batches', 'warehouse_bin_id')) {
                    $table->unsignedBigInteger('warehouse_bin_id')->nullable()->after('warehouse_id');
                }
                if (! Schema::hasColumn('stock_batches', 'serial_number')) {
                    $table->string('serial_number', 100)->nullable()->after('batch_number');
                }
                if (! Schema::hasColumn('stock_batches', 'quantity')) {
                    $table->decimal('quantity', 15, 3)->default(0)->after('serial_number');
                }
                if (! Schema::hasColumn('stock_batches', 'expiration_date')) {
                    $table->date('expiration_date')->nullable()->after('quantity');
                }
            });

            if (Schema::hasColumn('stock_batches', 'warehouse_bin_id') && Schema::hasTable('warehouse_bins')) {
                $fkExists = (bool) DB::selectOne(
                    "SELECT 1 AS ok FROM pg_constraint WHERE conname = 'stock_batches_warehouse_bin_id_foreign'"
                );
                if (! $fkExists && DB::getDriverName() === 'pgsql') {
                    Schema::table('stock_batches', function (Blueprint $table): void {
                        $table->foreign('warehouse_bin_id')
                            ->references('id')
                            ->on('warehouse_bins')
                            ->nullOnDelete();
                    });
                }
            }

            // Backfill WMS quantity from remaining_qty when empty.
            if (Schema::hasColumn('stock_batches', 'remaining_qty') && Schema::hasColumn('stock_batches', 'quantity')) {
                DB::statement('UPDATE stock_batches SET quantity = remaining_qty WHERE quantity = 0 AND remaining_qty <> 0');
            }

            if (DB::getDriverName() === 'pgsql') {
                DB::statement('CREATE INDEX IF NOT EXISTS stock_batches_wms_fefo_idx ON stock_batches (tenant_id, product_id, expiration_date)');
                DB::statement('CREATE INDEX IF NOT EXISTS stock_batches_wms_bin_idx ON stock_batches (tenant_id, warehouse_bin_id)');
            }
        }

        $this->applyRls('stock_batches');
    }

    public function down(): void
    {
        // Do not drop legacy stock_batches — only reverse WMS columns when present alongside qty.
        if (! Schema::hasTable('stock_batches')) {
            return;
        }

        if (Schema::hasColumn('stock_batches', 'qty')) {
            if (Schema::hasColumn('stock_batches', 'warehouse_bin_id')) {
                Schema::table('stock_batches', function (Blueprint $table): void {
                    $table->dropForeign(['warehouse_bin_id']);
                });
            }

            Schema::table('stock_batches', function (Blueprint $table): void {
                $cols = array_values(array_filter([
                    Schema::hasColumn('stock_batches', 'quantity') ? 'quantity' : null,
                    Schema::hasColumn('stock_batches', 'expiration_date') ? 'expiration_date' : null,
                    Schema::hasColumn('stock_batches', 'serial_number') ? 'serial_number' : null,
                    Schema::hasColumn('stock_batches', 'warehouse_bin_id') ? 'warehouse_bin_id' : null,
                ]));
                if ($cols !== []) {
                    $table->dropColumn($cols);
                }
            });

            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('DROP POLICY IF EXISTS tenant_isolation_stock_batches ON stock_batches');
            DB::statement('DROP POLICY IF EXISTS tenant_isolation_policy ON stock_batches');
            DB::statement('DROP POLICY IF EXISTS tenant_isolation_stock_batches_autometria ON stock_batches');
        }

        Schema::dropIfExists('stock_batches');
    }

    private function applyRls(string $table): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! Schema::hasTable($table)) {
            return;
        }

        $setting = "NULLIF(current_setting('app.current_tenant_id', true), '')::BIGINT";

        DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
        DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
        DB::statement("DROP POLICY IF EXISTS tenant_isolation_{$table} ON {$table}");
        DB::statement("DROP POLICY IF EXISTS tenant_isolation_policy ON {$table}");

        DB::statement(
            "CREATE POLICY tenant_isolation_{$table} ON {$table}
             FOR ALL
             TO PUBLIC
             USING (tenant_id = {$setting})
             WITH CHECK (tenant_id = {$setting})"
        );

        $hasAutometriaUser = (bool) DB::selectOne(
            "SELECT 1 AS ok FROM pg_roles WHERE rolname = 'autometria_user'"
        );
        if ($hasAutometriaUser) {
            DB::statement("DROP POLICY IF EXISTS tenant_isolation_{$table}_autometria ON {$table}");
            DB::statement(
                "CREATE POLICY tenant_isolation_{$table}_autometria ON {$table}
                 FOR ALL
                 TO autometria_user
                 USING (tenant_id = {$setting})
                 WITH CHECK (tenant_id = {$setting})"
            );
        }
    }
};
