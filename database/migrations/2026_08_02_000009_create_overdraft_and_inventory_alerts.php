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

/**
 * Блок 3.2: технический овердрафт склада (offline-чеки имеют фискальный приоритет)
 * + журнал складских алертов.
 *
 * orders.status — обычный VARCHAR(255) (не нативный PG enum), поэтому новое
 * значение 'completed_with_overdraft' не требует ALTER TYPE ... ADD VALUE.
 * Достаточно нового кейса в OrderStatusEnum.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('stock_batches', 'is_overdraft')) {
            Schema::table('stock_batches', function (Blueprint $table): void {
                $table->boolean('is_overdraft')->default(false);
            });
        }

        // Технические овердрафт-партии имеют отрицательный remaining_qty.
        DB::statement('ALTER TABLE stock_batches DROP CONSTRAINT IF EXISTS chk_stock_batches_remaining_non_negative');
        DB::statement(<<<'SQL'
            ALTER TABLE stock_batches
            ADD CONSTRAINT chk_stock_batches_remaining_non_negative
            CHECK (remaining_qty >= 0 OR is_overdraft = true)
        SQL);

        // Овердрафт допускает отрицательный фактический остаток (reserved остаётся >= 0).
        DB::statement('ALTER TABLE stocks DROP CONSTRAINT IF EXISTS chk_stocks_non_negative');
        DB::statement('ALTER TABLE stocks ADD CONSTRAINT chk_stocks_non_negative CHECK (reserved >= 0)');

        if (! Schema::hasTable('inventory_alerts')) {
            Schema::create('inventory_alerts', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_id')->nullable()->constrained('products_services')->nullOnDelete();
                $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
                $table->string('type', 40);
                $table->text('message');
                $table->timestampTz('resolved_at')->nullable();
                $table->timestampsTz();
                $table->index(['tenant_id', 'resolved_at']);
                $table->index(['tenant_id', 'type']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('inventory_alerts');

        DB::statement('ALTER TABLE stocks DROP CONSTRAINT IF EXISTS chk_stocks_non_negative');
        DB::statement('ALTER TABLE stock_batches DROP CONSTRAINT IF EXISTS chk_stock_batches_remaining_non_negative');

        if (Schema::hasColumn('stock_batches', 'is_overdraft')) {
            Schema::table('stock_batches', function (Blueprint $table): void {
                $table->dropColumn('is_overdraft');
            });
        }
    }
};
