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
        Schema::create('order_item_workers', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_item_id')->constrained('order_items')->cascadeOnDelete();
            $table->foreignId('worker_id')->constrained('users')->cascadeOnDelete();
            $table->decimal('commission_rate', 6, 2)->default(0);
            $table->decimal('earned_amount', 12, 2)->default(0);
            $table->timestampsTz();
            $table->index(['tenant_id', 'order_item_id']);
            $table->index(['tenant_id', 'worker_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('order_item_workers');
    }
};
