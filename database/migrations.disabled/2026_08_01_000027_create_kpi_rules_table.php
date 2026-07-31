<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('kpi_rules', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->nullable()->constrained()->nullOnDelete();
            $table->string('applies_to', 20); // order|item
            $table->decimal('percent', 12, 2)->nullable();
            $table->decimal('fixed_amount', 12, 2)->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->index(['tenant_id', 'role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_rules');
    }
};
