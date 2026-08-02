<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Блок 2.5: детализация списаний партий (FIFO COGS).
 *
 * Таблица фиксирует точную привязку списанной партии к позиции заказа
 * с закупочной стоимостью на момент продажи — первоисточник для расчёта COGS.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stock_lot_deductions')) {
            Schema::create('stock_lot_deductions', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
                $table->foreignId('order_item_id')->nullable()->constrained('order_items')->nullOnDelete();
                $table->foreignId('stock_batch_id')->constrained('stock_batches')->cascadeOnDelete();
                $table->foreignId('warehouse_id')->nullable()->constrained()->nullOnDelete();
                $table->foreignId('product_id')->nullable()->constrained('products_services')->nullOnDelete();
                $table->decimal('quantity', 14, 3)->default(0);
                $table->decimal('unit_cost', 14, 2)->default(0);
                $table->decimal('total_cost', 14, 2)->default(0);
                $table->timestampTz('deducted_at')->useCurrent();
                $table->timestampsTz();
                $table->index(['tenant_id', 'order_id']);
                $table->index(['tenant_id', 'order_item_id']);
                $table->index(['tenant_id', 'product_id']);
                $table->index(['tenant_id', 'deducted_at']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_lot_deductions');
    }
};
