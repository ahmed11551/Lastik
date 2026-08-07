<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Services\Procurement;

use Autometria\Models\ProductService;
use Autometria\Models\PurchaseOrderDraft;
use Autometria\Models\PurchaseOrderDraftItem;
use Autometria\Services\Analytics\DemandForecasterService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Auto-Procurement engine (v1.4.0 Sprint 3).
 *
 * Scans products flagged as stockout risk, groups them by preferred supplier,
 * and generates PurchaseOrderDraft(s) honouring MOQ.
 */
final class AutoOrderGeneratorService
{
    public function __construct(
        private readonly DemandForecasterService $forecaster,
    ) {}

    /**
     * Generate drafts for all at-risk products of a tenant.
     *
     * @return array{drafts: int, items: int}
     */
    public function generateForTenant(int $tenantId, int $lookbackDays = 90): array
    {
        $risks = $this->forecaster->riskScan($tenantId, $lookbackDays);

        // Group product ids by preferred supplier.
        $bySupplier = $risks->groupBy(function (array $r) use ($tenantId): ?int {
            $p = ProductService::query()->withoutGlobalScopes()->find($r['product_id']);

            return $p?->preferred_supplier_id;
        })->filter(fn ($group, $supplierId) => $supplierId !== null);

        $draftsCount = 0;
        $itemsCount = 0;

        // set_current_tenant_id ДО любых queries (как в CalculateReorderPointJob):
        // применяет RLS-контекст на connection, который переиспользуется Eloquent.
        set_current_tenant_id($tenantId);

        try {
            foreach ($bySupplier as $supplierId => $group) {
                $draft = PurchaseOrderDraft::query()->withoutGlobalScopes()->forceCreate([
                    'tenant_id' => $tenantId,
                    'supplier_id' => (int) $supplierId,
                    'status' => 'draft',
                    'total_amount' => 0,
                    'currency' => 'RUB',
                    'notes' => 'Auto-generated from demand forecast (stockout risk).',
                ]);

                $total = 0.0;
                foreach ($group as $r) {
                    $product = ProductService::query()->withoutGlobalScopes()->find($r['product_id']);
                    if ($product === null) {
                        continue;
                    }

                    $suggested = max(0, (float) $r['reorder_point'] - (float) $r['current_stock']);
                    $moq = (float) ($product->moq ?? 0);
                    $qty = $suggested > 0 ? $suggested : $moq;
                    if ($moq > 0 && $qty < $moq) {
                        $qty = $moq;
                    }
                    if ($qty <= 0) {
                        continue;
                    }

                    $unitCost = (float) ($product->base_price ?? 0);
                    $subtotal = round($qty * $unitCost, 2);

                    PurchaseOrderDraftItem::query()->withoutGlobalScopes()->forceCreate([
                        'tenant_id' => $tenantId,
                        'purchase_order_draft_id' => $draft->id,
                        'product_id' => $product->id,
                        'suggested_qty' => round($qty, 3),
                        'approved_qty' => 0,
                        'unit_cost' => round($unitCost, 2),
                        'subtotal' => $subtotal,
                    ]);

                    $total += $subtotal;
                    $itemsCount++;
                }

                if ($itemsCount === 0 || $draft->items()->count() === 0) {
                    $draft->delete();
                    continue;
                }

                $draft->update(['total_amount' => round($total, 2)]);
                $draftsCount++;
            }
        } catch (\Throwable $e) {
            throw $e;
        }

        return ['drafts' => $draftsCount, 'items' => $itemsCount];
    }
}
