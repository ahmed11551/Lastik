<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cash_shifts', function (Blueprint $table) {
            // Z-report fields (Block 2.1): expected vs actual cash reconciliation.
            if (! Schema::hasColumn('cash_shifts', 'expected_cash')) {
                $table->decimal('expected_cash', 14, 2)->default(0)->after('closing_amount');
            }
            if (! Schema::hasColumn('cash_shifts', 'shortage')) {
                $table->decimal('shortage', 14, 2)->default(0)->after('expected_cash');
            }
            if (! Schema::hasColumn('cash_shifts', 'overage')) {
                $table->decimal('overage', 14, 2)->default(0)->after('shortage');
            }
            if (! Schema::hasColumn('cash_shifts', 'z_report')) {
                $table->jsonb('z_report')->nullable()->after('overage');
            }
            if (! Schema::hasColumn('cash_shifts', 'expires_at')) {
                $table->timestamp('expires_at')->nullable()->after('opened_at');
            }
        });

        Schema::table('cash_movements', function (Blueprint $table) {
            if (! Schema::hasColumn('cash_movements', 'direction')) {
                $table->string('direction', 16)->nullable()->after('type');
            }
        });
    }

    public function down(): void
    {
        Schema::table('cash_shifts', function (Blueprint $table) {
            $table->dropColumn(['expected_cash', 'shortage', 'overage', 'z_report', 'expires_at']);
        });
        Schema::table('cash_movements', function (Blueprint $table) {
            $table->dropColumn(['direction']);
        });
    }
};
