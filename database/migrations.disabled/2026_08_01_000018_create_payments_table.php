<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('payments', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('shift_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('payee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->string('method', 50); // cash|card|transfer|telegram|mixed
            $table->string('type', 50); // payment|refund|prepayment
            $table->decimal('amount', 12, 2);
            $table->string('status', 20)->default('pending'); // pending|completed|failed|refunded
            $table->text('note')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'order_id']);
            $table->index(['tenant_id', 'shift_id']);
            $table->index(['tenant_id', 'created_by']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
    }
};
