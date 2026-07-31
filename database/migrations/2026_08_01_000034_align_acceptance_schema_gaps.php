<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Закрывает расхождения модель ↔ миграция для приёмки (п. 45 / 49).
 * article + external_id сосуществуют: article — внутренний артикул, external_id — ID из 1С.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products_services', function (Blueprint $table) {
            if (! Schema::hasColumn('products_services', 'article')) {
                $table->string('article', 100)->nullable()->after('type');
            }
            if (! Schema::hasColumn('products_services', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('unit');
            }
            if (! Schema::hasColumn('products_services', 'brand')) {
                $table->string('brand')->nullable()->after('name');
            }
        });

        if (Schema::hasColumn('products_services', 'article')
            && ! Schema::hasIndex('products_services', 'products_services_tenant_article_uidx')
        ) {
            Schema::table('products_services', function (Blueprint $table) {
                $table->unique(['tenant_id', 'article'], 'products_services_tenant_article_uidx');
            });
        }

        Schema::table('orders', function (Blueprint $table) {
            if (! Schema::hasColumn('orders', 'scenario')) {
                $table->string('scenario', 40)->default('without_installation')->after('status');
            }
            if (! Schema::hasColumn('orders', 'number')) {
                $table->string('number', 50)->nullable()->after('id');
            }
            if (! Schema::hasColumn('orders', 'payment_status')) {
                $table->string('payment_status', 40)->default('unpaid')->after('scenario');
            }
            if (! Schema::hasColumn('orders', 'created_by')) {
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('orders', 'locked_at')) {
                $table->timestampTz('locked_at')->nullable();
            }
        });

        Schema::table('customers', function (Blueprint $table) {
            if (! Schema::hasColumn('customers', 'inn')) {
                $table->string('inn', 20)->nullable()->after('email');
            }
            if (! Schema::hasColumn('customers', 'kpp')) {
                $table->string('kpp', 20)->nullable()->after('inn');
            }
            if (! Schema::hasColumn('customers', 'name')) {
                $table->string('name')->nullable()->after('type');
            }
        });

        Schema::table('users', function (Blueprint $table) {
            if (! Schema::hasColumn('users', 'password_hash')) {
                $table->string('password_hash')->nullable()->after('email');
            }
            if (! Schema::hasColumn('users', 'devices_limit')) {
                $table->unsignedTinyInteger('devices_limit')->default(2)->after('telegram_id');
            }
            if (! Schema::hasColumn('users', 'is_active')) {
                $table->boolean('is_active')->default(true);
            }
            if (! Schema::hasColumn('users', 'last_login_at')) {
                $table->timestampTz('last_login_at')->nullable();
            }
        });

        Schema::table('roles', function (Blueprint $table) {
            if (! Schema::hasColumn('roles', 'permissions')) {
                $table->jsonb('permissions')->nullable()->after('slug');
            }
        });

        Schema::table('warehouses', function (Blueprint $table) {
            if (! Schema::hasColumn('warehouses', 'location_id')) {
                $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            }
        });

        Schema::table('cash_shifts', function (Blueprint $table) {
            if (! Schema::hasColumn('cash_shifts', 'location_id')) {
                $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            }
            if (! Schema::hasColumn('cash_shifts', 'user_id')) {
                $table->foreignId('user_id')->nullable()->constrained('users')->nullOnDelete();
            }
            if (! Schema::hasColumn('cash_shifts', 'opened_at')) {
                $table->timestampTz('opened_at')->nullable();
            }
            if (! Schema::hasColumn('cash_shifts', 'totals')) {
                $table->jsonb('totals')->nullable();
            }
        });

        Schema::table('locations', function (Blueprint $table) {
            if (! Schema::hasColumn('locations', 'timezone')) {
                $table->string('timezone', 100)->default('Europe/Moscow')->after('address');
            }
            if (! Schema::hasColumn('locations', 'is_active')) {
                $table->boolean('is_active')->default(true)->after('timezone');
            }
        });

        Schema::table('permissions', function (Blueprint $table) {
            if (! Schema::hasColumn('permissions', 'section')) {
                $table->string('section', 50)->nullable()->after('slug');
            }
            if (! Schema::hasColumn('permissions', 'action')) {
                $table->string('action', 50)->nullable()->after('section');
            }
        });

        Schema::table('prices', function (Blueprint $table) {
            if (! Schema::hasColumn('prices', 'type')) {
                $table->string('type', 40)->default('retail')->after('product_id');
            }
        });

        Schema::table('kpi_rules', function (Blueprint $table) {
            if (! Schema::hasColumn('kpi_rules', 'product_id')) {
                $table->foreignId('product_id')->nullable()->constrained('products_services')->nullOnDelete();
            }
            if (! Schema::hasColumn('kpi_rules', 'role_id')) {
                $table->foreignId('role_id')->nullable()->constrained('roles')->nullOnDelete();
            }
        });

        Schema::table('order_items', function (Blueprint $table) {
            if (! Schema::hasColumn('order_items', 'kpi_percent')) {
                $table->decimal('kpi_percent', 6, 3)->nullable()->after('discount');
            }
            if (! Schema::hasColumn('order_items', 'kpi_amount')) {
                $table->decimal('kpi_amount', 12, 2)->nullable()->after('kpi_percent');
            }
        });
    }

    public function down(): void
    {
        // Intentionally non-destructive on down for acceptance safety.
    }
};
