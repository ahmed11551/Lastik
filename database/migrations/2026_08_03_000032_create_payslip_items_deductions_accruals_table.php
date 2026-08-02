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
        Schema::create('payslip_items', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->unsignedBigInteger('payslip_id');
            $table->string('type'); // EARNING, DEDUCTION
            $table->string('label');
            $table->decimal('amount', 12, 2)->default(0);
            $table->unsignedBigInteger('source_id')->nullable(); // earning_id / deduction_id
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->foreign('payslip_id')->references('id')->on('payslips')->onDelete('cascade');
            $table->index(['tenant_id', 'payslip_id']);
        });

        Schema::create('deductions', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('name');
            $table->string('type')->default('FIXED'); // FIXED, PERCENT
            $table->decimal('value', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->index(['tenant_id', 'is_active']);
        });

        Schema::create('accrual_rules', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('tenant_id');
            $table->string('name');
            $table->string('type')->default('KPI_PERCENT'); // KPI_PERCENT, FIXED, BONUS
            $table->decimal('value', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            $table->index(['tenant_id', 'is_active']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('accrual_rules');
        Schema::dropIfExists('deductions');
        Schema::dropIfExists('payslip_items');
    }
};
