<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cash_shifts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('opened_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('closed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('opened');
            $table->decimal('opening_amount', 12, 2)->default(0);
            $table->decimal('closing_amount', 12, 2)->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->text('note')->nullable();
            $table->timestampsTz();
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cash_shifts');
    }
};
