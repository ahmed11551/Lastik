<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stocks', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('warehouse_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products_services')->cascadeOnDelete();

            $table->unsignedInteger('actual')->default(0);
            $table->unsignedInteger('reserved')->default(0);
            $table->unsignedInteger('available')->default(0);

            $table->timestampsTz();

            $table->unique(['tenant_id', 'warehouse_id', 'product_id']);
            $table->index(['tenant_id', 'warehouse_id']);
            $table->index(['tenant_id', 'product_id']);
            $table->check('available >= 0', 'stocks_available_non_negative');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stocks');
    }
};
