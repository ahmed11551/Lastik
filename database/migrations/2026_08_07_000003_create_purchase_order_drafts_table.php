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
        Schema::create('purchase_order_drafts', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('supplier_id');
            $table->string('status', 20)->default('draft'); // draft|approved|sent|cancelled
            $table->decimal('total_amount', 14, 2)->default(0);
            $table->string('currency', 3)->default('RUB');
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['tenant_id', 'status']);
            $table->foreign('supplier_id')->references('id')->on('suppliers')->onDelete('cascade');
        });

        Schema::create('purchase_order_draft_items', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('purchase_order_draft_id');
            $table->unsignedBigInteger('product_id');
            $table->decimal('suggested_qty', 12, 3)->default(0);
            $table->decimal('approved_qty', 12, 3)->default(0);
            $table->decimal('unit_cost', 14, 2)->default(0);
            $table->decimal('subtotal', 14, 2)->default(0);
            $table->timestamps();

            $table->index(['tenant_id', 'purchase_order_draft_id']);
            $table->foreign('purchase_order_draft_id')->references('id')->on('purchase_order_drafts')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products_services')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_draft_items');
        Schema::dropIfExists('purchase_order_drafts');
    }
};
