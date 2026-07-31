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
        if (! Schema::hasTable('reservations')) {
            return;
        }

        Schema::table('reservations', function (Blueprint $table): void {
            $table->unique(
                ['tenant_id', 'order_item_id', 'stock_id', 'status'],
                'reservations_tenant_item_stock_status_unique'
            );
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('reservations')) {
            return;
        }

        Schema::table('reservations', function (Blueprint $table): void {
            $table->dropUnique('reservations_tenant_item_stock_status_unique');
        });
    }
};
