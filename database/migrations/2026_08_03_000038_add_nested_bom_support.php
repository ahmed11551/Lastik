<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 *
 * v1.1.0 / Вектор 4.A — Nested BOM (многоуровневые спецификации).
 * - Индекс на recipe_items.ingredient_id для рекурсивного развёртывания.
 * - Маркер is_semi_finished на products_services (полуфабрикат имеет свой recipe).
 * - max_bom_depth на tenants (ограничение глубины во избежание циклов при сборке).
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('recipe_items')) {
            $hasIdx = collect(Schema::getIndexes('recipe_items'))
                ->contains(fn ($i) => ($i['name'] ?? null) === 'recipe_items_tenant_ingredient_idx');
            if (! $hasIdx) {
                Schema::table('recipe_items', function (Blueprint $table): void {
                    $table->index(['tenant_id', 'ingredient_id'], 'recipe_items_tenant_ingredient_idx');
                });
            }
        }

        if (Schema::hasTable('products_services') && ! Schema::hasColumn('products_services', 'is_semi_finished')) {
            Schema::table('products_services', function (Blueprint $table): void {
                $table->boolean('is_semi_finished')->default(false);
                $table->index(['tenant_id', 'is_semi_finished'], 'ps_tenant_semi_finished_idx');
            });
        }

        if (Schema::hasTable('tenants') && ! Schema::hasColumn('tenants', 'max_bom_depth')) {
            Schema::table('tenants', function (Blueprint $table): void {
                $table->unsignedTinyInteger('max_bom_depth')->default(5);
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('recipe_items')) {
            $hasIdx = collect(Schema::getIndexes('recipe_items'))
                ->contains(fn ($i) => ($i['name'] ?? null) === 'recipe_items_tenant_ingredient_idx');
            if ($hasIdx) {
                Schema::table('recipe_items', function (Blueprint $table): void {
                    $table->dropIndex('recipe_items_tenant_ingredient_idx');
                });
            }
        }

        if (Schema::hasTable('products_services') && Schema::hasColumn('products_services', 'is_semi_finished')) {
            Schema::table('products_services', function (Blueprint $table): void {
                $table->dropColumn('is_semi_finished');
                $table->dropIndex('ps_tenant_semi_finished_idx');
            });
        }

        if (Schema::hasTable('tenants') && Schema::hasColumn('tenants', 'max_bom_depth')) {
            Schema::table('tenants', function (Blueprint $table): void {
                $table->dropColumn('max_bom_depth');
            });
        }
    }
};
