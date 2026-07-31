<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_shifts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->timestamp('opened_at');
            $table->timestamp('closed_at')->nullable();
            $table->json('opening_balance')->nullable();
            $table->json('closing_balance')->nullable();
            $table->json('totals')->nullable(); // payments/cash_movements aggregation
            $table->text('note')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'location_id']);
            $table->index(['tenant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_shifts');
    }
};
