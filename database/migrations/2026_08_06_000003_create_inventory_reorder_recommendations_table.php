<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 *
 * Sprint 3 — AI Inventory reorder recommendations + RLS BIGINT.
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('inventory_reorder_recommendations')) {
            Schema::create('inventory_reorder_recommendations', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('warehouse_id');
                $table->unsignedBigInteger('product_id');
                $table->decimal('d_avg', 15, 3)->default(0);
                $table->decimal('safety_stock', 15, 3)->default(0);
                $table->decimal('rop', 15, 3)->default(0);
                $table->decimal('on_hand', 15, 3)->default(0);
                $table->decimal('suggested_qty', 15, 3)->default(0);
                $table->boolean('is_dead_stock')->default(false);
                $table->string('severity', 16)->default('ok'); // ok|warn|critical
                $table->unsignedSmallInteger('lead_time_days')->default(7);
                $table->unsignedSmallInteger('lookback_days')->default(30);
                $table->timestampTz('calculated_at')->nullable();
                $table->timestamps();

                $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
                $table->foreign('warehouse_id')->references('id')->on('warehouses')->cascadeOnDelete();
                $table->foreign('product_id')->references('id')->on('products_services')->cascadeOnDelete();

                $table->unique(
                    ['tenant_id', 'warehouse_id', 'product_id'],
                    'inv_reorder_tenant_wh_product_uq'
                );
                $table->index(['tenant_id', 'severity'], 'inv_reorder_tenant_severity_idx');
                $table->index(['tenant_id', 'is_dead_stock'], 'inv_reorder_tenant_dead_idx');
            });
        }

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $setting = "NULLIF(current_setting('app.current_tenant_id', true), '')::BIGINT";

        DB::statement('ALTER TABLE inventory_reorder_recommendations ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE inventory_reorder_recommendations FORCE ROW LEVEL SECURITY');
        DB::statement('DROP POLICY IF EXISTS tenant_isolation_inventory_reorder_recommendations ON inventory_reorder_recommendations');
        DB::statement(
            "CREATE POLICY tenant_isolation_inventory_reorder_recommendations ON inventory_reorder_recommendations
             FOR ALL
             TO PUBLIC
             USING (tenant_id = {$setting})
             WITH CHECK (tenant_id = {$setting})"
        );

        $hasAutometriaUser = (bool) DB::selectOne(
            "SELECT 1 AS ok FROM pg_roles WHERE rolname = 'autometria_user'"
        );
        if ($hasAutometriaUser) {
            DB::statement('DROP POLICY IF EXISTS tenant_isolation_inventory_reorder_recommendations_autometria ON inventory_reorder_recommendations');
            DB::statement(
                "CREATE POLICY tenant_isolation_inventory_reorder_recommendations_autometria ON inventory_reorder_recommendations
                 FOR ALL
                 TO autometria_user
                 USING (tenant_id = {$setting})
                 WITH CHECK (tenant_id = {$setting})"
            );
            DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON inventory_reorder_recommendations TO autometria_user');
            DB::statement('GRANT USAGE, SELECT ON SEQUENCE inventory_reorder_recommendations_id_seq TO autometria_user');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql' && Schema::hasTable('inventory_reorder_recommendations')) {
            DB::statement('DROP POLICY IF EXISTS tenant_isolation_inventory_reorder_recommendations_autometria ON inventory_reorder_recommendations');
            DB::statement('DROP POLICY IF EXISTS tenant_isolation_inventory_reorder_recommendations ON inventory_reorder_recommendations');
            DB::statement('ALTER TABLE inventory_reorder_recommendations NO FORCE ROW LEVEL SECURITY');
        }

        Schema::dropIfExists('inventory_reorder_recommendations');
    }
};
