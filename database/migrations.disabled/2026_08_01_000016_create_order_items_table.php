<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('order_items', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->nullable()->constrained('products_services')->nullOnDelete();
            $table->string('type', 20); // product|service
            $table->json('snapshot');
            $table->unsignedInteger('qty')->default(1);
            $table->decimal('price', 12, 2);
            $table->decimal('discount', 12, 2)->default(0);
            $table->decimal('kpi_percent', 12, 2)->nullable();
            $table->decimal('kpi_amount', 12, 2)->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'order_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_items');
    }
};
