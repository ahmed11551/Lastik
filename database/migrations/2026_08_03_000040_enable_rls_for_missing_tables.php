<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 *
 * v1.1.0 / Security hardening: RLS defense-in-depth для таблиц,
 * пропущенных в 000038 (categories, product_variants, one_c_sync_logs).
 * Модели этих таблиц наследуют TenantModel (Laravel scope фильтрует
 * на уровне приложения), но БД-уровень изоляции отсутствовал — прямой
 * SQL мимо scope видел данные всех тенантов. Закрываем политикой
 * tenant_isolation по стандарту 000037 (app.current_tenant_id).
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $tables = ['categories', 'product_variants', 'one_c_sync_logs'];

        foreach ($tables as $table) {
            // Включаем FORCE ROW LEVEL SECURITY (даже для owner/суперпользователя).
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");

            // Политика изоляции тенантов (USING + WITH CHECK единообразно со 000037).
            $policy = "{$table}_tenant_isolation";
            DB::statement("DROP POLICY IF EXISTS {$policy} ON {$table}");
            $setting = "NULLIF(current_setting('app.current_tenant_id', true), '')::bigint";
            DB::statement("CREATE POLICY {$policy} ON {$table} FOR ALL TO PUBLIC USING (tenant_id = {$setting}) WITH CHECK (tenant_id = {$setting})");
        }
    }

    public function down(): void
    {
        $tables = ['categories', 'product_variants', 'one_c_sync_logs'];

        foreach ($tables as $table) {
            $policy = "{$table}_tenant_isolation";
            DB::statement("DROP POLICY IF EXISTS {$policy} ON {$table}");
            // Снимаем FORCE (возвращаем к состоянию до миграции — RLS выключен либо не форсится).
            DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
        }
    }
};
