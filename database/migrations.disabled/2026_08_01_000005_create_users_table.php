<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('users', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('role_id')->constrained();

            $table->string('name');
            $table->string('email')->unique();
            $table->string('password');
            $table->string('phone')->nullable();
            $table->string('position')->nullable();

            $table->json('devices')->nullable();
            $table->tinyInteger('devices_limit')->default(2);
            $table->timestamp('last_login_at')->nullable();
            $table->timestamp('email_verified_at')->nullable();

            $table->rememberToken();
            $table->timestampsTz();

            $table->index(['tenant_id', 'location_id']);
            $table->index(['tenant_id', 'role_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('users');
    }
};
