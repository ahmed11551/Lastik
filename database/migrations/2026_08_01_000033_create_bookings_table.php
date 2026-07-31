<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('post_id')->constrained()->cascadeOnDelete();
            $table->string('customer_name');
            $table->string('customer_phone');
            $table->dateTime('start_time');
            $table->dateTime('end_time');
            $table->string('status')->default('booked');
            $table->timestampsTz();
            $table->index(['tenant_id', 'post_id', 'start_time', 'end_time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
