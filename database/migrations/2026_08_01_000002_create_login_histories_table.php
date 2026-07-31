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
        Schema::create('login_histories', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent')->nullable();
            $table->string('device_name')->nullable();
            $table->string('organization')->nullable();
            $table->string('location')->nullable();
            $table->string('action', 100);
            $table->json('metadata')->nullable();
            $table->timestampsTz();

            $table->index(['tenant_id', 'user_id'], 'login_histories_tenant_user_idx');
            $table->index(['tenant_id', 'created_at'], 'login_histories_tenant_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('login_histories');
    }
};
