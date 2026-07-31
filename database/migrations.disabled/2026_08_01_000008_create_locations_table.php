<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('name', 255);
            $table->string('timezone', 100)->default('UTC');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};
