<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Блок 2.4: Интеграция с 1С (CommerceML 2.10).
 *  - external_id (uuid, indexed) для справочников и прайс-листов.
 *  - таблица categories (номенклатурные группы из 1С).
 *  - таблица product_variants (SKU/артикулы, маппинг на product).
 *  - таблица one_c_sync_logs (история обменов).
 */
return new class extends Migration
{
    public function up(): void
    {
        // external_id уже присутствует в products_services и warehouses (ранние миграции)
        // как uuid. 1С шлёт произвольные строковые ИД (напр. "CAT-1", GUID), поэтому
        // приводим external_id к строке (varchar) везде для совместимости.
        if (Schema::hasColumn('products_services', 'external_id')) {
            DB::statement('ALTER TABLE products_services ALTER COLUMN external_id TYPE varchar(100)');
        }
        if (Schema::hasColumn('warehouses', 'external_id')) {
            DB::statement('ALTER TABLE warehouses ALTER COLUMN external_id TYPE varchar(100)');
        }
        if (! Schema::hasColumn('prices', 'external_id')) {
            Schema::table('prices', function (Blueprint $table) {
                $table->string('external_id', 100)->nullable()->index();
            });
        } else {
            DB::statement('ALTER TABLE prices ALTER COLUMN external_id TYPE varchar(100)');
        }

        // Номенклатурные группы (Категории) из 1С.
        if (! Schema::hasTable('categories')) {
            Schema::create('categories', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->string('name');
                $table->string('external_id', 100)->nullable()->index();
                $table->unsignedBigInteger('parent_id')->nullable();
                $table->timestampsTz();
                $table->index(['tenant_id', 'external_id']);
            });
        }

        // Связь товара с категорией (вместо строкового category).
        if (! Schema::hasColumn('products_services', 'category_id')) {
            Schema::table('products_services', function (Blueprint $table) {
                $table->unsignedBigInteger('category_id')->nullable();
                $table->foreign('category_id')->references('id')->on('categories')->nullOnDelete();
            });
        }

        // Варианты товара (SKU/артикулы) из 1С. Остатки по-прежнему в stocks (по product_id).
        if (! Schema::hasTable('product_variants')) {
            Schema::create('product_variants', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->foreignId('product_id')->constrained('products_services')->cascadeOnDelete();
                $table->string('sku')->nullable();
                $table->string('barcode')->nullable();
                $table->string('external_id', 100)->nullable()->index();
                $table->timestampsTz();
                $table->index(['tenant_id', 'external_id']);
            });
        }

        // История обменов с 1С.
        if (! Schema::hasTable('one_c_sync_logs')) {
            Schema::create('one_c_sync_logs', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->foreignId('tenant_id')->nullable()->constrained()->nullOnDelete();
                $table->string('file_name');
                $table->string('status', 20)->default('pending'); // pending|processing|done|failed
                $table->unsignedInteger('processed_count')->default(0);
                $table->text('errors')->nullable();
                $table->timestampsTz();
                $table->index(['tenant_id', 'status']);
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('one_c_sync_logs');
        Schema::dropIfExists('product_variants');
        Schema::dropIfExists('categories');
        if (Schema::hasColumn('products_services', 'category_id')) {
            Schema::table('products_services', function (Blueprint $table) {
                $table->dropForeign(['category_id']);
                $table->dropColumn('category_id');
            });
        }
        if (Schema::hasColumn('prices', 'external_id')) {
            Schema::table('prices', function (Blueprint $table) {
                $table->dropColumn('external_id');
            });
        }
    }
};
