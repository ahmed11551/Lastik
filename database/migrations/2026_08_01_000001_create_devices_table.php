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
        Schema::create('devices', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('user_id');
            $table->string('device_name');
            $table->string('device_type', 50);
            $table->string('fingerprint')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamp('last_active_at')->nullable();
            $table->boolean('is_current')->default(false);
            $table->timestampsTz();

            $table->index(['tenant_id', 'user_id'], 'devices_tenant_user_idx');
            $table->unique(['user_id', 'fingerprint'], 'devices_user_fingerprint_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};
