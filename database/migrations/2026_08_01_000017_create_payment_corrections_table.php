<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payment_corrections', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2)->default(0);
            $table->text('reason')->nullable();
            $table->timestampsTz();
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_corrections');
    }
};
