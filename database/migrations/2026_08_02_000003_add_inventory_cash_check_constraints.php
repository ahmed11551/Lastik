<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * P0: Database-level CHECK constraints (Postgres) to guarantee stock and batch
 * balances can NEVER go negative, even if application logic is bypassed.
 *
 * - stocks:      available >= 0 AND actual >= 0 AND reserved >= 0
 * - stock_batches: remaining_qty >= 0
 * - cash_shifts:  opening_amount >= 0 AND closing_amount >= 0
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement(
            'ALTER TABLE stocks
             ADD CONSTRAINT chk_stocks_non_negative
             CHECK (available >= 0 AND actual >= 0 AND reserved >= 0)'
        );

        DB::statement(
            'ALTER TABLE stock_batches
             ADD CONSTRAINT chk_stock_batches_remaining_non_negative
             CHECK (remaining_qty >= 0)'
        );

        DB::statement(
            'ALTER TABLE cash_shifts
             ADD CONSTRAINT chk_cash_shifts_amounts_non_negative
             CHECK (opening_amount >= 0 AND closing_amount >= 0)'
        );
    }

    public function down(): void
    {
        if (Schema::getConnection()->getDriverName() !== 'pgsql') {
            return;
        }

        DB::statement('ALTER TABLE stocks DROP CONSTRAINT IF EXISTS chk_stocks_non_negative');
        DB::statement('ALTER TABLE stock_batches DROP CONSTRAINT IF EXISTS chk_stock_batches_remaining_non_negative');
        DB::statement('ALTER TABLE cash_shifts DROP CONSTRAINT IF EXISTS chk_cash_shifts_amounts_non_negative');
    }
};
