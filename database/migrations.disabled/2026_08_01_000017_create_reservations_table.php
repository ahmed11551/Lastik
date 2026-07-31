<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reservations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('qty');
            $table->string('status', 20)->default('active'); // active|released|used|cancelled|conflict
            $table->text('reason')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'stock_id']);
            $table->index(['tenant_id', 'order_item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('reservations');
    }
};
