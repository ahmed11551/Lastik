<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Блок 2.3 (аудит Grok): точная схема fiscal_receipts.
 * Пересоздаём таблицу под новую статус-машину и атомарный claim.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('fiscal_receipts');

        Schema::create('fiscal_receipts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cash_shift_id')->nullable()->constrained('cash_shifts')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();

            $table->string('operation', 20);                 // sell | sell_refund | buy | buy_refund
            $table->string('status', 20)->default('pending'); // PENDING..NEEDS_RECONCILE
            $table->string('idempotency_key', 100);
            $table->uuid('driver_request_id');                // передаётся провайдеру ККТ как идемпотентный ключ

            $table->decimal('total_amount', 14, 2);           // CHECK total_amount >= 0

            $table->jsonb('payload_snapshot');                // иммутабельный снимок позиций/НДС/платежей

            $table->string('fn_number', 50)->nullable();      // ФН
            $table->bigInteger('fd_number')->nullable();      // ФД
            $table->string('fp_value', 64)->nullable();       // ФП / ФПД
            $table->text('qr_code_url')->nullable();

            $table->timestampTz('locked_at')->nullable();     // захват воркером (claim)
            $table->unsignedInteger('attempt')->default(0);
            $table->text('last_error')->nullable();

            $table->timestampsTz();

            $table->unique(['tenant_id', 'idempotency_key']);
            $table->unique(['tenant_id', 'payment_id']);
            $table->index(['tenant_id', 'status']);
        });

        // CHECK constraint на неотрицательную сумму (Postgres guard).
        if (Schema::getConnection()->getDriverName() === 'pgsql') {
            \Illuminate\Support\Facades\DB::statement(
                'ALTER TABLE fiscal_receipts ADD CONSTRAINT fiscal_receipts_total_amount_check CHECK (total_amount >= 0)'
            );
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('fiscal_receipts');
    }
};
