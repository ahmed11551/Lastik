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
        Schema::table('import_jobs', function (Blueprint $table): void {
            if (! Schema::hasColumn('import_jobs', 'file_name')) {
                $table->string('file_name', 255)->nullable()->after('source');
            }
            if (! Schema::hasColumn('import_jobs', 'channel')) {
                $table->string('channel', 40)->nullable()->after('file_name'); // auto_1c | manual_upload
            }
            if (! Schema::hasColumn('import_jobs', 'error_message')) {
                $table->text('error_message')->nullable()->after('errors');
            }
        });
    }

    public function down(): void
    {
        Schema::table('import_jobs', function (Blueprint $table): void {
            if (Schema::hasColumn('import_jobs', 'error_message')) {
                $table->dropColumn('error_message');
            }
            if (Schema::hasColumn('import_jobs', 'channel')) {
                $table->dropColumn('channel');
            }
            if (Schema::hasColumn('import_jobs', 'file_name')) {
                $table->dropColumn('file_name');
            }
        });
    }
};
