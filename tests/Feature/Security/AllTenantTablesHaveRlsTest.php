<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 */

declare(strict_types=1);

use Illuminate\Support\Facades\DB;

/**
 * Gate P0 / Hermes — реестр RLS.
 * Любая таблица с колонкой tenant_id (кроме системных) ОБЯЗАНА иметь
 * FORCE ROW LEVEL SECURITY + активную политику tenant_isolation_*.
 * Тест падает, если хотя бы одна таблица нарушает это правило.
 */
it('every tenant-scoped table enforces FORCE RLS with a tenant_isolation policy', function (): void {
    // Таблицы, которые легитимно не имеют tenant_id / RLS (системные / кросс-тенантные).
    $exceptions = [
        'tenants',                // справочник арендаторов
        'personal_access_tokens', // Sanctum (привязаны к user, не tenant)
        'migrations',
        'sessions',
        'password_reset_tokens',
        'failed_jobs',
        'jobs',
        'cache',
        'cache_locks',
        'queue_monitor',
        'audit_logs',             // append-only, контролируется триггером
    ];

    $rows = DB::select(
        "SELECT c.relname AS table_name,
                c.relforcerowsecurity AS force_rls,
                (SELECT count(*) FROM pg_policies p
                  WHERE p.schemaname='public' AND p.tablename=c.relname
                    AND p.policyname LIKE 'tenant_isolation_%') AS policy_count
         FROM pg_class c
         JOIN pg_namespace n ON n.oid = c.relnamespace
         WHERE n.nspname = 'public' AND c.relkind = 'r'
           AND EXISTS (
               SELECT 1 FROM information_schema.columns col
               WHERE col.table_schema='public' AND col.table_name=c.relname
                 AND col.column_name='tenant_id'
           )
         ORDER BY c.relname"
    );

    $violations = [];
    foreach ($rows as $row) {
        if (in_array($row->table_name, $exceptions, true)) {
            continue;
        }
        $force = ($row->force_rls === 't' || $row->force_rls === true || $row->force_rls === 1);
        $hasPolicy = (int) $row->policy_count > 0;
        if (! $force || ! $hasPolicy) {
            $violations[] = sprintf(
                '%s (force_rls=%s, policies=%d)',
                $row->table_name,
                var_export($row->force_rls, true),
                $row->policy_count
            );
        }
    }

    expect($violations)->toBeEmpty(
        'Обнаружены tenant-таблицы без FORCE RLS / политики: '.implode(', ', $violations)
    );
});
