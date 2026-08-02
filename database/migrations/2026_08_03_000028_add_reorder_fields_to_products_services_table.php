<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products_services', function (Blueprint $table) {
            $table->decimal('min_stock', 12, 3)->nullable()->after('base_price');
            $table->decimal('max_stock', 12, 3)->nullable()->after('min_stock');
            $table->decimal('reorder_point', 12, 3)->nullable()->after('max_stock');
        });
    }

    public function down(): void
    {
        Schema::table('products_services', function (Blueprint $table) {
            $table->dropColumn(['min_stock', 'max_stock', 'reorder_point']);
        });
    }
};
