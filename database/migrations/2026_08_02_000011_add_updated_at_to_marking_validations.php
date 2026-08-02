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
        if (Schema::hasTable('marking_validations') && ! Schema::hasColumn('marking_validations', 'updated_at')) {
            Schema::table('marking_validations', function (Blueprint $table): void {
                $table->timestampTz('updated_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasTable('marking_validations') && Schema::hasColumn('marking_validations', 'updated_at')) {
            Schema::table('marking_validations', function (Blueprint $table): void {
                $table->dropColumn('updated_at');
            });
        }
    }
};
