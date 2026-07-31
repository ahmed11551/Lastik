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
        if (! Schema::hasTable('warehouses')) {
            return;
        }

        Schema::table('warehouses', function (Blueprint $table): void {
            if (! Schema::hasColumn('warehouses', 'external_id')) {
                $table->string('external_id')->nullable()->after('name');
                $table->unique(['tenant_id', 'external_id'], 'warehouses_tenant_external_id_unique');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('warehouses') || ! Schema::hasColumn('warehouses', 'external_id')) {
            return;
        }

        Schema::table('warehouses', function (Blueprint $table): void {
            $table->dropUnique('warehouses_tenant_external_id_unique');
            $table->dropColumn('external_id');
        });
    }
};
