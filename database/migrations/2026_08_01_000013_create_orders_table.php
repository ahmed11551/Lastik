<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_seller_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('master_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('status')->default('draft');
            $table->decimal('total', 12, 2)->default(0);
            $table->timestampsTz();
            $table->index('tenant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
