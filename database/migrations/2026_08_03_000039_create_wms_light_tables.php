<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 *
 * v1.1.0 / Вектор 4.B — WMS Light (адресное хранение + серийный учёт).
 * - storage_cells: ячейки/стеллажи склада (zone/rack/shelf/bin).
 * - serial_numbers: серийные номера деталей/товаров (привязка к партии).
 * - stock_batch_cells: размещение партии в ячейке (кол-во в ячейке).
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('storage_cells')) {
            Schema::create('storage_cells', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
                $table->string('code', 64);
                $table->string('zone', 32)->nullable();
                $table->string('rack', 32)->nullable();
                $table->string('shelf', 32)->nullable();
                $table->string('bin', 32)->nullable();
                $table->text('description')->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestampsTz();

                $table->unique(['tenant_id', 'warehouse_id', 'code']);
                $table->index(['tenant_id', 'warehouse_id']);
                $table->index(['tenant_id', 'is_active']);
            });
        }

        if (! Schema::hasTable('serial_numbers')) {
            Schema::create('serial_numbers', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('products_services')->cascadeOnDelete();
                $table->foreignId('stock_batch_id')->nullable()->constrained('stock_batches')->nullOnDelete();
                $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
                $table->string('serial', 128);
                $table->string('status', 24)->default('IN_STOCK');
                $table->timestampsTz();

                $table->unique(['tenant_id', 'serial']);
                $table->index(['tenant_id', 'product_id']);
                $table->index(['tenant_id', 'stock_batch_id']);
                $table->index(['tenant_id', 'status']);
            });
        }

        if (! Schema::hasTable('stock_batch_cells')) {
            Schema::create('stock_batch_cells', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('stock_batch_id')->constrained('stock_batches')->cascadeOnDelete();
                $table->foreignId('storage_cell_id')->constrained('storage_cells')->cascadeOnDelete();
                $table->decimal('quantity', 14, 3)->default(0);
                $table->timestampsTz();

                $table->unique(['tenant_id', 'stock_batch_id', 'storage_cell_id']);
                $table->index(['tenant_id', 'storage_cell_id']);
            });
        }

        if (DB::getDriverName() === 'pgsql') {
            foreach (['storage_cells', 'serial_numbers', 'stock_batch_cells'] as $table) {
                DB::statement("ALTER TABLE {$table} ENABLE ROW LEVEL SECURITY");
                DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");
                DB::statement("DROP POLICY IF EXISTS tenant_isolation_{$table} ON {$table}");
                DB::statement(
                    "CREATE POLICY tenant_isolation_{$table} ON {$table}
                     USING (tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::bigint)
                     WITH CHECK (tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::bigint)"
                );
            }
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            foreach (['stock_batch_cells', 'serial_numbers', 'storage_cells'] as $table) {
                if (! Schema::hasTable($table)) {
                    continue;
                }
                DB::statement("DROP POLICY IF EXISTS tenant_isolation_{$table} ON {$table}");
            }
        }

        Schema::dropIfExists('stock_batch_cells');
        Schema::dropIfExists('serial_numbers');
        Schema::dropIfExists('storage_cells');
    }
};
