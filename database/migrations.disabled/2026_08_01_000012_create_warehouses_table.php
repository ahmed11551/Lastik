<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('warehouses', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 255);
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('warehouses');
    }
};
