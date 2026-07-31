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
        Schema::create('import_jobs', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('source', 50); // commerceml2|excel_customers
            $table->string('status', 20)->default('pending'); // pending|processing|completed|failed
            $table->json('summary')->nullable();
            $table->json('errors')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'source']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('import_jobs');
    }
};
