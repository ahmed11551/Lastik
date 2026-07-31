<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('issuances', function (Blueprint $table) {
            if (! Schema::hasColumn('issuances', 'order_item_id')) {
                $table->foreignId('order_item_id')->nullable()->constrained('order_items')->nullOnDelete();
            }
            if (! Schema::hasColumn('issuances', 'warehouse_id')) {
                $table->foreignId('warehouse_id')->nullable()->constrained('warehouses')->nullOnDelete();
            }
            if (! Schema::hasColumn('issuances', 'qty')) {
                $table->decimal('qty', 12, 3)->default(0);
            }
            if (! Schema::hasColumn('issuances', 'issued_by')) {
                $table->foreignId('issued_by')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('issuances', 'issued_at')) {
                $table->timestampTz('issued_at')->nullable();
            }
            if (! Schema::hasColumn('issuances', 'basis')) {
                $table->string('basis', 100)->nullable(); // to_customer|to_work
            }
        });

        if (! Schema::hasTable('stock_transfers')) {
            Schema::create('stock_transfers', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('products_services')->cascadeOnDelete();
                $table->foreignId('from_warehouse_id')->constrained('warehouses')->cascadeOnDelete();
                $table->foreignId('to_warehouse_id')->constrained('warehouses')->cascadeOnDelete();
                $table->decimal('qty', 12, 3);
                $table->text('reason');
                $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
                $table->timestampsTz();
                $table->index('tenant_id');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_transfers');
    }
};
