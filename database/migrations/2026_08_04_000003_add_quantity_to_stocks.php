<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 *
 * P0: добавление колонки quantity decimal(12,2) в stocks для дробных
 * физических остатков (масло 1.5 л, крепёж 0.5 кг и т.п.).
 * actual/reserved/available остаются numeric(14,3) для точности учёта;
 * quantity — дробный физический объём в единице измерения товара (л/кг).
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('stocks', 'quantity')) {
            Schema::table('stocks', function (Blueprint $table): void {
                $table->decimal('quantity', 12, 2)->default(0)->comment('Дробный физический остаток (л/кг)');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('stocks', 'quantity')) {
            Schema::table('stocks', function (Blueprint $table): void {
                $table->dropColumn('quantity');
            });
        }
    }
};
