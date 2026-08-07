<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 *
 * P0 (audit): stocks.actual / reserved / available must be decimal end-to-end.
 * Create-migration historically used unsignedInteger; production/BOM migration
 * only altered on pgsql. This migration normalises all drivers idempotently.
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('stocks')) {
            return;
        }

        $driver = DB::getDriverName();

        if ($driver === 'pgsql') {
            DB::statement('ALTER TABLE stocks ALTER COLUMN actual TYPE numeric(14,3) USING actual::numeric(14,3)');
            DB::statement('ALTER TABLE stocks ALTER COLUMN reserved TYPE numeric(14,3) USING reserved::numeric(14,3)');
            DB::statement('ALTER TABLE stocks ALTER COLUMN available TYPE numeric(14,3) USING available::numeric(14,3)');

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('ALTER TABLE stocks MODIFY actual DECIMAL(14,3) NOT NULL DEFAULT 0');
            DB::statement('ALTER TABLE stocks MODIFY reserved DECIMAL(14,3) NOT NULL DEFAULT 0');
            DB::statement('ALTER TABLE stocks MODIFY available DECIMAL(14,3) NOT NULL DEFAULT 0');
        }
    }

    public function down(): void
    {
        // Irreversible precision upgrade — keep decimal on rollback.
    }
};
