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

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fiscal_receipts', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('cash_shift_id')->nullable()->constrained('cash_shifts')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained('payments')->nullOnDelete();

            $table->string('type', 20);                  // sell | sell_refund | buy | buy_refund
            $table->string('status', 20)->default('pending');
            $table->string('idempotency_key', 100)->unique();

            $table->string('fiscal_document_number', 50)->nullable();   // ФД
            $table->string('fiscal_storage_number', 50)->nullable();   // ФН
            $table->string('fiscal_sign', 64)->nullable();             // ФП / ФПД
            $table->string('qr_code_url', 512)->nullable();

            $table->jsonb('payload')->nullable();          // состав позиций, НДС, суммы
            $table->text('error_message')->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->timestampTz('fiscalized_at')->nullable();

            $table->timestampsTz();
            $table->index(['tenant_id', 'status']);
        });

        // 54-ФЗ требует НДС по позициям. Добавляем ставку в OrderItem (по умолчанию без НДС).
        if (! Schema::hasColumn('order_items', 'vat_rate')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->string('vat_rate', 10)->default('none');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('order_items', 'vat_rate')) {
            Schema::table('order_items', function (Blueprint $table) {
                $table->dropColumn('vat_rate');
            });
        }

        Schema::dropIfExists('fiscal_receipts');
    }
};
