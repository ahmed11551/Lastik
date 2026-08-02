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
        if (! Schema::hasTable('branches')) {
            Schema::create('branches', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->string('code', 64);
                $table->string('address', 500)->nullable();
                $table->foreignId('default_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
                $table->boolean('is_active')->default(true);
                $table->timestampsTz();

                $table->unique(['tenant_id', 'code']);
                $table->index(['tenant_id', 'is_active']);
            });
        }

        if (! Schema::hasTable('warehouse_product_prices')) {
            Schema::create('warehouse_product_prices', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('products_services')->cascadeOnDelete();
                $table->decimal('price', 14, 2);
                $table->timestampsTz();

                $table->unique(['tenant_id', 'warehouse_id', 'product_id'], 'wh_product_prices_unique');
                $table->index(['tenant_id', 'warehouse_id']);
            });
        }

        if (! Schema::hasTable('stock_reservations')) {
            Schema::create('stock_reservations', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('products_services')->cascadeOnDelete();
                $table->decimal('quantity', 14, 3);
                $table->timestampTz('reserved_until');
                $table->string('status', 20)->default('ACTIVE');
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('reason', 255)->nullable();
                $table->timestampsTz();

                $table->index(['tenant_id', 'status', 'reserved_until']);
                $table->index(['tenant_id', 'warehouse_id', 'product_id']);
            });
        }

        Schema::table('warehouses', function (Blueprint $table): void {
            if (! Schema::hasColumn('warehouses', 'branch_id')) {
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            }
        });

        Schema::table('cash_shifts', function (Blueprint $table): void {
            if (! Schema::hasColumn('cash_shifts', 'branch_id')) {
                $table->foreignId('branch_id')->nullable()->constrained('branches')->nullOnDelete();
            }
            if (! Schema::hasColumn('cash_shifts', 'warehouse_id')) {
                $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            }
        });

        if (DB::getDriverName() === 'pgsql') {
            foreach (['branches', 'warehouse_product_prices', 'stock_reservations'] as $table) {
                if (! Schema::hasTable($table)) {
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
        if (DB::getDriverName() === 'pgsql') {
            foreach (['stock_reservations', 'warehouse_product_prices', 'branches'] as $table) {
                if (! Schema::hasTable($table)) {
                    continue;
                }
                DB::statement("DROP POLICY IF EXISTS tenant_isolation_{$table} ON {$table}");
            }
        }

        Schema::table('cash_shifts', function (Blueprint $table): void {
            if (Schema::hasColumn('cash_shifts', 'warehouse_id')) {
                $table->dropConstrainedForeignId('warehouse_id');
            }
            if (Schema::hasColumn('cash_shifts', 'branch_id')) {
                $table->dropConstrainedForeignId('branch_id');
            }
        });

        Schema::table('warehouses', function (Blueprint $table): void {
            if (Schema::hasColumn('warehouses', 'branch_id')) {
                $table->dropConstrainedForeignId('branch_id');
            }
        });

        Schema::dropIfExists('stock_reservations');
        Schema::dropIfExists('warehouse_product_prices');
        Schema::dropIfExists('branches');
    }
};
