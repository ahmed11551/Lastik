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
        if (! Schema::hasTable('recipes')) {
            Schema::create('recipes', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('products_services')->cascadeOnDelete();
                $table->decimal('yield_quantity', 14, 3)->default(1);
                $table->text('instructions')->nullable();
                $table->timestampsTz();

                $table->unique(['tenant_id', 'product_id']);
                $table->index(['tenant_id', 'product_id']);
            });
        }

        if (! Schema::hasTable('recipe_items')) {
            Schema::create('recipe_items', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('recipe_id')->constrained('recipes')->cascadeOnDelete();
                $table->foreignId('ingredient_id')->constrained('products_services')->cascadeOnDelete();
                $table->decimal('quantity', 14, 3); // брутто
                $table->decimal('waste_percentage', 8, 3)->default(0);
                $table->decimal('net_quantity', 14, 3)->default(0);
                $table->timestampsTz();

                $table->unique(['recipe_id', 'ingredient_id']);
                $table->index(['tenant_id', 'recipe_id']);
            });
        }

        if (! Schema::hasTable('production_orders')) {
            Schema::create('production_orders', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('recipe_id')->constrained('recipes')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('products_services')->cascadeOnDelete();
                $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
                $table->decimal('qty', 14, 3);
                $table->decimal('unit_cost', 14, 4)->default(0);
                $table->decimal('total_cost', 14, 2)->default(0);
                $table->foreignId('batch_id')->nullable()->constrained('stock_batches')->nullOnDelete();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->string('status', 32)->default('COMPLETED');
                $table->jsonb('ingredients')->nullable();
                $table->timestampsTz();

                $table->index(['tenant_id', 'created_at']);
                $table->index(['tenant_id', 'recipe_id']);
            });
        }

        if (! Schema::hasTable('modifiers')) {
            Schema::create('modifiers', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->string('name', 120);
                $table->string('code', 64)->nullable();
                $table->boolean('is_required')->default(false);
                $table->unsignedSmallInteger('min_select')->default(0);
                $table->unsignedSmallInteger('max_select')->default(1);
                $table->timestampsTz();

                $table->index(['tenant_id', 'name']);
            });
        }

        if (! Schema::hasTable('modifier_options')) {
            Schema::create('modifier_options', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('modifier_id')->constrained('modifiers')->cascadeOnDelete();
                $table->string('name', 120);
                $table->decimal('price', 14, 2)->default(0);
                $table->foreignId('ingredient_id')->nullable()->constrained('products_services')->nullOnDelete();
                $table->decimal('ingredient_qty', 14, 3)->default(0);
                $table->boolean('is_active')->default(true);
                $table->timestampsTz();

                $table->index(['tenant_id', 'modifier_id']);
            });
        }

        if (! Schema::hasTable('product_modifiers')) {
            Schema::create('product_modifiers', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('products_services')->cascadeOnDelete();
                $table->foreignId('modifier_id')->constrained('modifiers')->cascadeOnDelete();
                $table->unsignedInteger('sort_order')->default(0);
                $table->timestampsTz();

                $table->unique(['product_id', 'modifier_id']);
                $table->index(['tenant_id', 'product_id']);
            });
        }

        // BOM ingredients are fractional — stocks must store decimal qty.
        if (Schema::hasTable('stocks') && DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE stocks ALTER COLUMN actual TYPE numeric(14,3) USING actual::numeric(14,3)');
            DB::statement('ALTER TABLE stocks ALTER COLUMN reserved TYPE numeric(14,3) USING reserved::numeric(14,3)');
            DB::statement('ALTER TABLE stocks ALTER COLUMN available TYPE numeric(14,3) USING available::numeric(14,3)');
        }

        if (DB::getDriverName() === 'pgsql') {
            foreach (['recipes', 'recipe_items', 'production_orders', 'modifiers', 'modifier_options', 'product_modifiers'] as $table) {
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
            foreach (['product_modifiers', 'modifier_options', 'modifiers', 'production_orders', 'recipe_items', 'recipes'] as $table) {
                if (! Schema::hasTable($table)) {
                    continue;
                }
                DB::statement("DROP POLICY IF EXISTS tenant_isolation_{$table} ON {$table}");
            }
        }

        Schema::dropIfExists('product_modifiers');
        Schema::dropIfExists('modifier_options');
        Schema::dropIfExists('modifiers');
        Schema::dropIfExists('production_orders');
        Schema::dropIfExists('recipe_items');
        Schema::dropIfExists('recipes');
    }
};
