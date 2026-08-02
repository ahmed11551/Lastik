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
            return;
        }

        Schema::table('stock_batches', function (Blueprint $table): void {
            if (! Schema::hasColumn('stock_batches', 'supplier_order_id')) {
                $table->foreignId('supplier_order_id')
                    ->nullable()
                    ->after('product_id')
                    ->constrained('supplier_orders')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('stock_batches')) {
            return;
        }

        Schema::table('stock_batches', function (Blueprint $table): void {
            if (Schema::hasColumn('stock_batches', 'supplier_order_id')) {
                $table->dropConstrainedForeignId('supplier_order_id');
            }
        });
    }
};
