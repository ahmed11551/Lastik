<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_movements', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shift_id')->constrained()->cascadeOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->foreignId('payee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('type', 20); // inkasso|withdrawal|adjustment
            $table->decimal('amount', 12, 2);
            $table->text('note')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'shift_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_movements');
    }
};
