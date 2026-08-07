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
        Schema::table('products_services', function (Blueprint $table): void {
            $table->unsignedBigInteger('preferred_supplier_id')->nullable();
            $table->decimal('moq', 12, 3)->default(0);          // минимальная партия заказа
            $table->integer('lead_time_days')->default(0);        // срок поставки
            $table->decimal('safety_stock', 12, 3)->default(0);   // страховой запас

            $table->foreign('preferred_supplier_id')->references('id')->on('suppliers')->onDelete('set null');
            $table->index(['tenant_id', 'preferred_supplier_id']);
        });
    }

    public function down(): void
    {
        Schema::table('products_services', function (Blueprint $table): void {
            $table->dropForeign(['preferred_supplier_id']);
            $table->dropColumn(['preferred_supplier_id', 'moq', 'lead_time_days', 'safety_stock']);
        });
    }
};
