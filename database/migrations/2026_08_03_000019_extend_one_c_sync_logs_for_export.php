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
        if (! Schema::hasTable('one_c_sync_logs')) {
            return;
        }

        Schema::table('one_c_sync_logs', function (Blueprint $table): void {
            if (! Schema::hasColumn('one_c_sync_logs', 'direction')) {
                $table->string('direction', 16)->default('inbound')->after('tenant_id');
            }
            if (! Schema::hasColumn('one_c_sync_logs', 'channel')) {
                $table->string('channel', 32)->default('exchange')->after('direction');
            }
            if (! Schema::hasColumn('one_c_sync_logs', 'payload_size')) {
                $table->unsignedBigInteger('payload_size')->default(0)->after('processed_count');
            }
            if (! Schema::hasColumn('one_c_sync_logs', 'details')) {
                $table->jsonb('details')->nullable()->after('errors');
            }
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable('one_c_sync_logs')) {
            return;
        }

        Schema::table('one_c_sync_logs', function (Blueprint $table): void {
            foreach (['details', 'payload_size', 'channel', 'direction'] as $col) {
                if (Schema::hasColumn('one_c_sync_logs', $col)) {
                    $table->dropColumn($col);
                }
            }
        });
    }
};
