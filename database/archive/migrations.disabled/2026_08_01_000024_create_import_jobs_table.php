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
return new class extends Migration {
    public function up(): void { Schema::create('import_jobs', function (Blueprint $table) { $table->bigIncrements('id'); $table->foreignId('tenant_id')->constrained()->cascadeOnDelete(); $table->string('type'); $table->string('status')->default('pending'); $table->unsignedInteger('processed')->default(0); $table->unsignedInteger('failed')->default(0); $table->text('log')->nullable(); $table->timestampsTz(); $table->index('tenant_id'); }); }
    public function down(): void { Schema::dropIfExists('import_jobs'); }
};