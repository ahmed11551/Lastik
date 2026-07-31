<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('earnings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->json('rule_snapshot');
            $table->string('source', 20); // order|item
            $table->timestampsTz();

            $table->index(['tenant_id', 'order_id']);
            $table->index(['tenant_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('earnings');
    }
};
