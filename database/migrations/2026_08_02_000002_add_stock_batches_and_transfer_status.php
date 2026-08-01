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

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stock_batches')) {
            Schema::create('stock_batches', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('products_services')->cascadeOnDelete();
                $table->string('batch_number', 100)->nullable();
                $table->decimal('qty', 14, 3)->default(0);          // исходное кол-во партии
                $table->decimal('remaining_qty', 14, 3)->default(0); // остаток в партии
                $table->decimal('cost_price', 14, 2)->default(0);   // закупочная цена за единицу
                $table->timestampTz('received_at')->nullable();
                $table->timestampsTz();
                $table->index(['tenant_id', 'warehouse_id', 'product_id']);
                $table->index(['tenant_id', 'product_id', 'received_at']);
            });
        }

        Schema::table('stock_transfers', function (Blueprint $table) {
            if (! Schema::hasColumn('stock_transfers', 'status')) {
                $table->string('status', 20)->default('completed')->after('qty');
            }
            if (! Schema::hasColumn('stock_transfers', 'shipped_at')) {
                $table->timestampTz('shipped_at')->nullable();
            }
            if (! Schema::hasColumn('stock_transfers', 'received_at')) {
                $table->timestampTz('received_at')->nullable();
            }
            if (! Schema::hasColumn('stock_transfers', 'shipped_by')) {
                $table->foreignId('shipped_by')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('stock_transfers', 'received_by')) {
                $table->foreignId('received_by')->nullable()->constrained('users')->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('stock_transfers', function (Blueprint $table) {
            $table->dropColumn(['status', 'shipped_at', 'received_at', 'shipped_by', 'received_by']);
        });
        Schema::dropIfExists('stock_batches');
    }
};
