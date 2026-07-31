<?php
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::create('login_histories', function (Blueprint $table) { $table->bigIncrements('id'); $table->foreignId('tenant_id')->constrained()->cascadeOnDelete(); $table->foreignId('user_id')->constrained()->cascadeOnDelete(); $table->string('ip_address')->nullable(); $table->string('user_agent')->nullable(); $table->timestamp('login_at'); $table->timestampsTz(); $table->index('tenant_id'); }); }
    public function down(): void { Schema::dropIfExists('login_histories'); }
};