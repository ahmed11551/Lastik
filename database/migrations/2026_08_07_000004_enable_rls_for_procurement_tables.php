<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * v1.4.0 / Auto-Procurement security: RLS defense-in-depth для
 * product_classifications, purchase_order_drafts, purchase_order_draft_items.
 * Модели наследуют TenantModel (Laravel scope), но БД-уровень изоляции
 * отсутствовал. Закрываем политикой tenant_isolation по стандарту 000037.
 */
return new class extends Migration
{
    public function up(): void
    {
        $tables = ['product_classifications', 'purchase_order_drafts', 'purchase_order_draft_items'];

        foreach ($tables as $table) {
            DB::statement("ALTER TABLE {$table} FORCE ROW LEVEL SECURITY");

            $policy = "tenant_isolation_{$table}";
            DB::statement("DROP POLICY IF EXISTS {$policy} ON {$table}");
            $setting = "NULLIF(current_setting('app.current_tenant_id', true), '')::bigint";
            DB::statement("CREATE POLICY {$policy} ON {$table} FOR ALL TO PUBLIC USING (tenant_id = {$setting}) WITH CHECK (tenant_id = {$setting})");
        }
    }

    public function down(): void
    {
        $tables = ['product_classifications', 'purchase_order_drafts', 'purchase_order_draft_items'];

        foreach ($tables as $table) {
            $policy = "tenant_isolation_{$table}";
            DB::statement("DROP POLICY IF EXISTS {$policy} ON {$table}");
            DB::statement("ALTER TABLE {$table} NO FORCE ROW LEVEL SECURITY");
        }
    }
};
