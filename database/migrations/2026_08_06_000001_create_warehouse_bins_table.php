<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 *
 * WMS 2.0 — warehouse_bins (ячеистое хранение) + canonical RLS BIGINT.
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
        if (! Schema::hasTable('warehouse_bins')) {
            Schema::create('warehouse_bins', function (Blueprint $table): void {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('tenant_id');
                $table->unsignedBigInteger('warehouse_id');
                $table->string('code', 50);
                $table->string('zone', 30)->default('STORAGE');
                $table->decimal('max_weight_kg', 12, 3)->nullable();
                $table->decimal('max_volume_m3', 12, 3)->nullable();
                $table->boolean('is_active')->default(true);
                $table->timestamps();

                $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
                $table->foreign('warehouse_id')->references('id')->on('warehouses')->cascadeOnDelete();

                $table->unique(['tenant_id', 'warehouse_id', 'code'], 'warehouse_bins_tenant_wh_code_uq');
                $table->index(['tenant_id', 'warehouse_id', 'zone'], 'warehouse_bins_tenant_wh_zone_idx');
                $table->index(['tenant_id', 'is_active'], 'warehouse_bins_tenant_active_idx');
            });

            DB::statement("ALTER TABLE warehouse_bins ADD CONSTRAINT warehouse_bins_zone_chk CHECK (zone IN ('RECEIVING', 'STORAGE', 'PICKING', 'SHIPPING'))");
        }

        if (DB::getDriverName() !== 'pgsql') {
            return;
        }

        $setting = "NULLIF(current_setting('app.current_tenant_id', true), '')::BIGINT";

        DB::statement('ALTER TABLE warehouse_bins ENABLE ROW LEVEL SECURITY');
        DB::statement('ALTER TABLE warehouse_bins FORCE ROW LEVEL SECURITY');
        DB::statement('DROP POLICY IF EXISTS tenant_isolation_warehouse_bins ON warehouse_bins');
        DB::statement('DROP POLICY IF EXISTS tenant_isolation_policy ON warehouse_bins');

        // Canonical: FOR ALL + BIGINT cast. PUBLIC covers autometria_user / lastik_app at runtime.
        DB::statement(
            "CREATE POLICY tenant_isolation_warehouse_bins ON warehouse_bins
             FOR ALL
             TO PUBLIC
             USING (tenant_id = {$setting})
             WITH CHECK (tenant_id = {$setting})"
        );

        // Explicit prod role (docker/postgres/init → autometria_user) when present.
        $hasAutometriaUser = (bool) DB::selectOne(
            "SELECT 1 AS ok FROM pg_roles WHERE rolname = 'autometria_user'"
        );
        if ($hasAutometriaUser) {
            DB::statement('DROP POLICY IF EXISTS tenant_isolation_warehouse_bins_autometria ON warehouse_bins');
            DB::statement(
                "CREATE POLICY tenant_isolation_warehouse_bins_autometria ON warehouse_bins
                 FOR ALL
                 TO autometria_user
                 USING (tenant_id = {$setting})
                 WITH CHECK (tenant_id = {$setting})"
            );
            DB::statement('GRANT SELECT, INSERT, UPDATE, DELETE ON warehouse_bins TO autometria_user');
            DB::statement('GRANT USAGE, SELECT ON SEQUENCE warehouse_bins_id_seq TO autometria_user');
        }
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql' && Schema::hasTable('warehouse_bins')) {
            DB::statement('DROP POLICY IF EXISTS tenant_isolation_warehouse_bins_autometria ON warehouse_bins');
            DB::statement('DROP POLICY IF EXISTS tenant_isolation_warehouse_bins ON warehouse_bins');
            DB::statement('DROP POLICY IF EXISTS tenant_isolation_policy ON warehouse_bins');
            DB::statement('ALTER TABLE warehouse_bins NO FORCE ROW LEVEL SECURITY');
        }

        Schema::dropIfExists('warehouse_bins');
    }
};
