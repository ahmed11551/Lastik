<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tenants', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->string('slug', 100)->unique();
            $table->string('name', 255);
            $table->string('timezone', 100)->default('UTC');
            $table->boolean('is_active')->default(true);
            $table->timestampsTz();

            $table->index('slug', 'tenants_slug_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tenants');
    }
};
