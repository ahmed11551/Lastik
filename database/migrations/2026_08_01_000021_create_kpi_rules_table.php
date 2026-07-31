<?php

declare(strict_types=1);
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
            $table->string('applies_to');
            $table->string('target_type');
            $table->decimal('percent', 6, 2)->nullable();
            $table->decimal('amount', 12, 2)->nullable();
            $table->timestamp('valid_from')->nullable();
            $table->timestamp('valid_to')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('kpi_rules');
    }
};
