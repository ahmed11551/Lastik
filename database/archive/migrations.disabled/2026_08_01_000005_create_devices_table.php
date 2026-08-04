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
    public function up(): void { Schema::create('devices', function (Blueprint $table) { $table->bigIncrements('id'); $table->foreignId('tenant_id')->constrained()->cascadeOnDelete(); $table->foreignId('user_id')->constrained()->cascadeOnDelete(); $table->string('device_name')->nullable(); $table->string('device_type')->nullable(); $table->string('fingerprint')->nullable(); $table->string('ip_address')->nullable(); $table->string('user_agent')->nullable(); $table->boolean('is_active')->default(true); $table->timestamp('last_active_at')->nullable(); $table->boolean('is_current')->default(false); $table->timestampsTz(); $table->index('tenant_id'); }); }
    public function down(): void { Schema::dropIfExists('devices'); }
};