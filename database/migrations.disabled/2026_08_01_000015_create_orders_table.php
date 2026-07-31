<?php

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
            $table->foreignId('location_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('customer_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('vehicle_id')->nullable()->constrained()->nullOnDelete();
            $table->string('number')->nullable();
            $table->string('scenario')->nullable(); // with_install|without_install
            $table->string('status')->default('created'); // created|in_progress|ready|issued|closed|cancelled
            $table->string('payment_status')->default('unpaid'); // unpaid|partial|paid|overpaid|refunded
            $table->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('assigned_seller_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('master_id')->nullable()->constrained('users')->nullOnDelete();
            $table->decimal('total', 12, 2)->default(0);
            $table->text('cancellation_reason')->nullable();
            $table->json('meta')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'location_id']);
            $table->index(['tenant_id', 'customer_id']);
            $table->index(['tenant_id', 'status']);
            $table->unique(['tenant_id', 'number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('orders');
    }
};
