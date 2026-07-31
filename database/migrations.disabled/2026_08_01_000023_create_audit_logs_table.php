<?php
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::create('audit_logs', function (Blueprint $table) { $table->bigIncrements('id'); $table->foreignId('tenant_id')->constrained()->cascadeOnDelete(); $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); $table->string('action'); $table->string('object_type'); $table->unsignedBigInteger('object_id')->nullable(); $table->json('old')->nullable(); $table->json('new')->nullable(); $table->json('metadata')->nullable(); $table->string('ip')->nullable(); $table->string('user_agent')->nullable(); $table->text('reason')->nullable(); $table->timestamp('created_at'); $table->index(['tenant_id','user_id']); $table->index(['tenant_id','object_type','object_id']); $table->index('created_at'); }); }
    public function down(): void { Schema::dropIfExists('audit_logs'); }
};