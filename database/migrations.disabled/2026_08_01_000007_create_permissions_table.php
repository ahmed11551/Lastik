<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('permissions', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('slug', 100);
            $table->string('section', 100);
            $table->string('action', 100);
            $table->timestampsTz();

            $table->unique(['tenant_id', 'slug']);
            $table->index(['tenant_id', 'section']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('permissions');
    }
};
