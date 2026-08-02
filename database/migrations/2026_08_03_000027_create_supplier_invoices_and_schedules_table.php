<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('supplier_invoices', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('supplier_order_id');
            $table->string('number')->nullable();
            $table->date('invoice_date')->nullable();
            $table->decimal('amount', 12, 2)->default(0);
            $table->string('file_path')->nullable();
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('supplier_order_id')->references('id')->on('supplier_orders')->onDelete('cascade');
            $table->index(['tenant_id', 'supplier_order_id']);
        });

        Schema::create('delivery_schedules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('supplier_order_id');
            $table->unsignedBigInteger('product_id');
            $table->date('planned_date')->nullable();
            $table->decimal('qty', 12, 3)->default(0);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('supplier_order_id')->references('id')->on('supplier_orders')->onDelete('cascade');
            $table->foreign('product_id')->references('id')->on('products_services')->onDelete('cascade');
            $table->index(['tenant_id', 'supplier_order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('delivery_schedules');
        Schema::dropIfExists('supplier_invoices');
    }
};
