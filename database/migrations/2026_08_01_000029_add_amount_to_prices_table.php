<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prices', function (Blueprint $table) {
            if (! Schema::hasColumn('prices', 'amount')) {
                $table->decimal('amount', 12, 2)->nullable()->after('price');
            }
        });

        Schema::table('prices', function (Blueprint $table) {
            if (Schema::hasColumn('prices', 'amount')) {
                $table->decimal('amount', 12, 2)->nullable(false)->default(0)->change();
            }
        });
    }

    public function down(): void
    {
        Schema::table('prices', function (Blueprint $table) {
            if (Schema::hasColumn('prices', 'amount')) {
                $table->dropColumn('amount');
            }
        });
    }
};
