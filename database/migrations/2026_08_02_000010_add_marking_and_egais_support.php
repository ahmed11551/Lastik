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
 * Блок 3.3: маркировка «Честный Знак» + ЕГАИС.
 * products → products_services (каталог товаров/услуг).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products_services', function (Blueprint $table): void {
            if (! Schema::hasColumn('products_services', 'is_marked')) {
                $table->boolean('is_marked')->default(false);
            }
            if (! Schema::hasColumn('products_services', 'marking_type')) {
                $table->string('marking_type', 32)->nullable();
            }
            if (! Schema::hasColumn('products_services', 'is_egais')) {
                $table->boolean('is_egais')->default(false);
            }
            if (! Schema::hasColumn('products_services', 'egais_alcocode')) {
                $table->string('egais_alcocode', 64)->nullable();
            }
        });

        Schema::table('order_items', function (Blueprint $table): void {
            if (! Schema::hasColumn('order_items', 'marking_code')) {
                $table->string('marking_code', 255)->nullable();
            }
            if (! Schema::hasColumn('order_items', 'gtin')) {
                $table->string('gtin', 14)->nullable();
            }
            if (! Schema::hasColumn('order_items', 'serial_number')) {
                $table->string('serial_number', 64)->nullable();
            }
        });

        if (! Schema::hasTable('marking_validations')) {
            Schema::create('marking_validations', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->string('marking_code', 255);
                $table->string('gtin', 14);
                $table->string('status', 32);
                $table->jsonb('response_payload')->nullable();
                $table->timestampTz('created_at')->useCurrent();

                $table->index(['tenant_id', 'gtin']);
                $table->index(['tenant_id', 'status']);
                $table->index(['tenant_id', 'marking_code']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('marking_validations');

        Schema::table('order_items', function (Blueprint $table): void {
            foreach (['marking_code', 'gtin', 'serial_number'] as $col) {
                if (Schema::hasColumn('order_items', $col)) {
                    $table->dropColumn($col);
                }
            }
        });

        Schema::table('products_services', function (Blueprint $table): void {
            foreach (['is_marked', 'marking_type', 'is_egais', 'egais_alcocode'] as $col) {
                if (Schema::hasColumn('products_services', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
