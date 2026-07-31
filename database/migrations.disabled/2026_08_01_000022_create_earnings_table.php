<?php
declare(strict_types=1);
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
return new class extends Migration {
    public function up(): void { Schema::create('earnings', function (Blueprint $table) { $table->bigIncrements('id'); $table->foreignId('tenant_id')->constrained()->cascadeOnDelete(); $table->foreignId('order_id')->nullable()->constrained()->nullOnDelete(); $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete(); $table->decimal('amount', 12, 2)->default(0); $table->jsonb('rule_snapshot')->nullable(); $table->string('source')->nullable(); $table->timestampsTz(); $table->index('tenant_id'); }); }
    public function down(): void { Schema::dropIfExists('earnings'); }
};