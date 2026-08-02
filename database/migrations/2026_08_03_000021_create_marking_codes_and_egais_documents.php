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
        if (! Schema::hasTable('marking_codes')) {
            Schema::create('marking_codes', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->string('code', 255);
                $table->string('gtin', 14)->nullable();
                $table->string('serial', 64)->nullable();
                $table->string('status', 32)->default('EMITTED');
                $table->foreignId('product_id')->nullable()->constrained('products_services')->nullOnDelete();
                $table->foreignId('receipt_id')->nullable()->constrained('fiscal_receipts')->nullOnDelete();
                $table->timestampsTz();

                $table->unique(['tenant_id', 'code']);
                $table->index(['tenant_id', 'status']);
                $table->index(['tenant_id', 'gtin']);
            });
        }

        if (! Schema::hasTable('egais_documents')) {
            Schema::create('egais_documents', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->string('doc_type', 32);
                $table->string('fsrar_id', 64);
                $table->string('status', 32)->default('DRAFT');
                $table->jsonb('payload')->nullable();
                $table->timestampsTz();

                $table->index(['tenant_id', 'doc_type']);
                $table->index(['tenant_id', 'status']);
                $table->index(['tenant_id', 'fsrar_id']);
            });
        }

        if (DB::getDriverName() === 'pgsql') {
            foreach (['marking_codes', 'egais_documents'] as $table) {
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
            foreach (['egais_documents', 'marking_codes'] as $table) {
                if (! Schema::hasTable($table)) {
                    continue;
                }
                DB::statement("DROP POLICY IF EXISTS tenant_isolation_{$table} ON {$table}");
            }
        }

        Schema::dropIfExists('egais_documents');
        Schema::dropIfExists('marking_codes');
    }
};
