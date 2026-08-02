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
        if (! Schema::hasTable('inventory_documents')) {
            Schema::create('inventory_documents', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('warehouse_id')->constrained('warehouses')->cascadeOnDelete();
                $table->foreignId('target_warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
                $table->string('type', 32);
                $table->string('status', 20)->default('DRAFT');
                $table->string('number', 64)->nullable();
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
                $table->timestampTz('posted_at')->nullable();
                $table->timestampsTz();

                $table->index(['tenant_id', 'type', 'status']);
                $table->index(['tenant_id', 'warehouse_id']);
                $table->unique(['tenant_id', 'number']);
            });
        }

        if (! Schema::hasTable('inventory_document_items')) {
            Schema::create('inventory_document_items', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('document_id')->constrained('inventory_documents')->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('products_services')->cascadeOnDelete();
                $table->decimal('quantity', 14, 3);
                $table->decimal('cost_price', 14, 2)->default(0);
                $table->string('reason', 500)->nullable();
                $table->string('sku', 100)->nullable();
                $table->string('name', 255)->nullable();
                $table->timestampsTz();

                $table->index(['tenant_id', 'document_id']);
                $table->index(['document_id', 'product_id']);
            });
        }

        if (DB::getDriverName() === 'pgsql') {
            foreach (['inventory_documents', 'inventory_document_items'] as $table) {
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
            foreach (['inventory_document_items', 'inventory_documents'] as $table) {
                if (! Schema::hasTable($table)) {
                    continue;
                }
                DB::statement("DROP POLICY IF EXISTS tenant_isolation_{$table} ON {$table}");
            }
        }

        Schema::dropIfExists('inventory_document_items');
        Schema::dropIfExists('inventory_documents');
    }
};
