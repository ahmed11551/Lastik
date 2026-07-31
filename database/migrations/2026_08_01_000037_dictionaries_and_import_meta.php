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
        if (! Schema::hasTable('dictionaries')) {
            Schema::create('dictionaries', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
                $table->string('type', 50); // order_status|payment_status|item_status|payment_form|cancel_reason|...
                $table->string('code', 50);
                $table->string('label');
                $table->unsignedInteger('sort')->default(0);
                $table->boolean('is_active')->default(true);
                $table->json('meta')->nullable();
                $table->timestampsTz();

                $table->unique(['tenant_id', 'type', 'code']);
                $table->index(['tenant_id', 'type']);
            });
        }

        Schema::table('import_jobs', function (Blueprint $table) {
            if (! Schema::hasColumn('import_jobs', 'created_by')) {
                $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            }
        });

        Schema::table('stock_conflicts', function (Blueprint $table) {
            if (! Schema::hasColumn('stock_conflicts', 'import_job_id')) {
                $table->foreignId('import_job_id')->nullable()->constrained('import_jobs')->nullOnDelete();
            }
            if (! Schema::hasColumn('stock_conflicts', 'resolved')) {
                $table->boolean('resolved')->default(false);
            }
            if (! Schema::hasColumn('stock_conflicts', 'message')) {
                $table->string('message')->nullable();
            }
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dictionaries');
    }
};
