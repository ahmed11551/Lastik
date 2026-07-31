<?php

declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('customers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('type')->default('individual');
            $table->string('legal_name')->nullable();
            $table->string('phone')->nullable();
            $table->string('email')->nullable();
            $table->timestampsTz();
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('customers');
    }
};
