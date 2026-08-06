<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 *
 * Sprint 1 (v1.3.0) — Web Push device subscriptions (VAPID).
 * Morph table (subscribable → User); no tenant_id (scoped via User).
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $connection = config('webpush.database_connection');
        $table = config('webpush.table_name', 'push_subscriptions');

        if (Schema::connection($connection)->hasTable($table)) {
            return;
        }

        Schema::connection($connection)->create($table, function (Blueprint $table): void {
            $table->bigIncrements('id');
            $table->morphs('subscribable', 'push_subscriptions_subscribable_morph_idx');
            $table->string('endpoint', 500)->unique();
            $table->string('public_key')->nullable();
            $table->string('auth_token')->nullable();
            $table->string('content_encoding')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection(config('webpush.database_connection'))
            ->dropIfExists(config('webpush.table_name', 'push_subscriptions'));
    }
};
