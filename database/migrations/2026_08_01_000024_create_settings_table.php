<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('settings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
            $table->string('group', 100);
            $table->string('key', 100);
            $table->json('value')->nullable();
            $table->string('scope', 50)->default('global'); // global|location|role|user
            $table->timestampsTz();

            $table->unique(['tenant_id', 'group', 'key', 'scope']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('settings');
    }
};
