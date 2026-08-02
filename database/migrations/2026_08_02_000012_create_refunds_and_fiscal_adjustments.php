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
 * Блок 3.4: возвраты и фискальные корректировки (возврат прихода 54-ФЗ).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('refunds')) {
            Schema::create('refunds', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('order_id')->constrained('orders')->cascadeOnDelete();
                $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();
                $table->foreignId('fiscal_receipt_id')->nullable()->constrained('fiscal_receipts')->nullOnDelete();
                $table->foreignId('cash_shift_id')->nullable()->constrained('cash_shifts')->nullOnDelete();
                $table->string('status', 32)->default('completed'); // pending, completed, failed
                $table->string('reason', 255)->nullable();
                $table->decimal('total_amount', 12, 2)->default(0);
                $table->unsignedBigInteger('created_by')->nullable();
                $table->timestampsTz();

                $table->index(['tenant_id', 'order_id']);
                $table->index(['tenant_id', 'status']);
            });
        }

        if (! Schema::hasTable('refund_items')) {
            Schema::create('refund_items', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('refund_id')->constrained('refunds')->cascadeOnDelete();
                $table->foreignId('order_item_id')->constrained('order_items')->cascadeOnDelete();
                $table->foreignId('product_id')->nullable()->constrained('products_services')->nullOnDelete();
                $table->decimal('qty', 12, 3);
                $table->decimal('amount', 12, 2)->default(0);
                $table->string('marking_code', 255)->nullable();
                $table->timestampsTz();

                $table->index(['tenant_id', 'refund_id']);
            });
        }

        if (Schema::hasTable('stock_lot_deductions') && ! Schema::hasColumn('stock_lot_deductions', 'refunded_qty')) {
            Schema::table('stock_lot_deductions', function (Blueprint $table): void {
                $table->decimal('refunded_qty', 12, 3)->default(0);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('stock_lot_deductions') && Schema::hasColumn('stock_lot_deductions', 'refunded_qty')) {
            Schema::table('stock_lot_deductions', function (Blueprint $table): void {
                $table->dropColumn('refunded_qty');
            });
        }

        Schema::dropIfExists('refund_items');
        Schema::dropIfExists('refunds');
    }
};
