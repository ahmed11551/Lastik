<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! Schema::hasTable('audit_logs')) {
            return;
        }

        DB::unprepared(<<<'SQL'
            CREATE OR REPLACE FUNCTION audit_logs_append_only()
            RETURNS trigger
            LANGUAGE plpgsql
            AS $$
            BEGIN
                RAISE EXCEPTION 'audit_logs table is append-only';
            END;
            $$;

            DROP TRIGGER IF EXISTS trg_audit_logs_append_only ON audit_logs;

            CREATE TRIGGER trg_audit_logs_append_only
                BEFORE UPDATE OR DELETE ON audit_logs
                FOR EACH ROW
                EXECUTE FUNCTION audit_logs_append_only();
        SQL);
    }

    public function down(): void
    {
        if (DB::getDriverName() !== 'pgsql' || ! Schema::hasTable('audit_logs')) {
            return;
        }

        DB::unprepared(<<<'SQL'
            DROP TRIGGER IF EXISTS trg_audit_logs_append_only ON audit_logs;
            DROP FUNCTION IF EXISTS audit_logs_append_only();
        SQL);
    }
};
