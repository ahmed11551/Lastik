<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 *
 * FIX: RLS policies for Greenfield tables (purchase/payroll/portal) must read
 * `app.current_tenant_id` — the setting the application actually sets via
 * set_current_tenant_id() — NOT `autometria.tenant_id` (which is never set).
 * Without this, RLS isolation for these tables is broken under a non-superuser
 * DB role. Idempotent: drops and recreates the policy.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $tables = [
            'suppliers', 'supplier_orders', 'supplier_order_items',
            'supplier_invoices', 'delivery_schedules',
            'payroll_periods', 'payslips', 'payslip_items', 'deductions', 'accrual_rules',
            'customer_portal_tokens',
        ];

        foreach ($tables as $table) {
            DB::statement("DROP POLICY IF EXISTS {$table}_tenant_isolation ON {$table}");
            DB::statement("
                CREATE POLICY {$table}_tenant_isolation ON {$table}
                USING (tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::bigint)
                WITH CHECK (tenant_id = NULLIF(current_setting('app.current_tenant_id', true), '')::bigint)
            ");
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $tables = [
            'suppliers', 'supplier_orders', 'supplier_order_items',
            'supplier_invoices', 'delivery_schedules',
            'payroll_periods', 'payslips', 'payslip_items', 'deductions', 'accrual_rules',
            'customer_portal_tokens',
        ];

        foreach ($tables as $table) {
            DB::statement("DROP POLICY IF EXISTS {$table}_tenant_isolation ON {$table}");
        }
    }
};
