<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @package    Autometria\Core
 * @copyright  (c) 2026 Себиев Ахмед Сулейманович (Sebiev Akhmed Suleymanovich). All Rights Reserved.
 * @author     Себиев Ахмед Сулейманович (Chief Software Architect / Lead Developer)
 * @license    Proprietary & Confidential. Unauthorized copying, distribution,
 *             modification, or reverse engineering of this file, via any medium,
 *             is strictly prohibited.
 *
 * NOTICE: All information contained herein is, and remains the property of
 * Себиев Ахмед Сулейманович. The intellectual and technical concepts contained
 * herein are proprietary and protected by trade secret and copyright law.
 */
/**
 * LASTIK B2B SaaS Engine Core
 *
 * @copyright  (c) 2026 Себиев Ахмед Сулейманович (Sebiev Akhmed Suleymanovich). All Rights Reserved.
 * @author     Себиев Ахмед Сулейманович (Chief Software Architect / Lead Developer)
 * @license    Proprietary & Confidential. Unauthorized copying, distribution,
 *             modification, or reverse engineering of this file, via any medium,
 *             is strictly prohibited.
 *
 * NOTICE: All information contained herein is, and remains the property of
 * Себиев Ахмед Сулейманович. The intellectual and technical concepts contained
 * herein are proprietary and protected by trade secret and copyright law.
 */
/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 */

declare(strict_types=1);

namespace Autometria\Services\CommerceML;

use Autometria\DTOs\CommerceML\StockBalanceDTO;
use Autometria\Models\Stock;
use Autometria\Models\StockConflict;
use Autometria\Support\AuditLog;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Пакетный upsert остатков CommerceML с проверкой коллизий резервов.
 */
class CommerceMLBatchUpsertService
{
    public const BATCH_SIZE = 1000;

    /**
     * @param  Collection<int, StockBalanceDTO>|iterable<StockBalanceDTO>  $balances
     * @return array{processed: int, conflicts: int, skipped: int}
     */
    public function upsertStockBalances(int $tenantId, iterable $balances, ?int $importJobId = null, ?int $userId = null): array
    {
        $collection = $balances instanceof Collection
            ? $balances
            : collect(iterator_to_array($balances, false));

        $summary = ['processed' => 0, 'conflicts' => 0, 'skipped' => 0];

        $collection->chunk(self::BATCH_SIZE)->each(function (Collection $chunk) use ($tenantId, $importJobId, $userId, &$summary): void {
            DB::transaction(function () use ($tenantId, $chunk, $importJobId, $userId, &$summary): void {
                $upsertData = [];
                $now = now();

                foreach ($chunk as $dto) {
                    /** @var StockBalanceDTO $dto */
                    $productId = $this->resolveProductId($tenantId, $dto->productExternalId);
                    $warehouseId = $this->resolveWarehouseId($tenantId, $dto->warehouseExternalId);

                    if ($productId === null || $warehouseId === null) {
                        $summary['skipped']++;

                        continue;
                    }

                    $existing = Stock::query()->withoutGlobalScopes()
                        ->where('tenant_id', $tenantId)
                        ->where('warehouse_id', $warehouseId)
                        ->where('product_id', $productId)
                        ->lockForUpdate()
                        ->first();

                    $reserved = $existing !== null ? (float) $existing->reserved : 0.0;
                    $incoming = (float) $dto->quantity;
                    $available = max(0.0, round($incoming - $reserved, 2));

                    if ($existing !== null && $incoming + 0.0001 < $reserved) {
                        $this->recordConflict(
                            $existing,
                            $importJobId,
                            $incoming,
                            $reserved,
                            $userId,
                        );
                        $summary['conflicts']++;
                    }

                    $upsertData[] = [
                        'tenant_id' => $tenantId,
                        'warehouse_id' => $warehouseId,
                        'product_id' => $productId,
                        'actual' => $incoming,
                        'reserved' => $reserved,
                        'available' => $available,
                        'created_at' => $existing?->created_at ?? $now,
                        'updated_at' => $now,
                    ];

                    if ($dto->price !== null) {
                        $amount = round((float) $dto->price, 2);
                        DB::table('prices')->updateOrInsert(
                            [
                                'tenant_id' => $tenantId,
                                'product_id' => $productId,
                            ],
                            [
                                'type' => 'retail',
                                'amount' => $amount,
                                'price' => $amount,
                                'updated_at' => $now,
                                'created_at' => $now,
                            ],
                        );
                    }

                    $summary['processed']++;
                }

                if ($upsertData !== []) {
                    DB::table('stocks')->upsert(
                        $upsertData,
                        ['tenant_id', 'warehouse_id', 'product_id'],
                        ['actual', 'reserved', 'available', 'updated_at'],
                    );
                }
            });
        });

        return $summary;
    }

    private function resolveProductId(int $tenantId, string $externalId): ?int
    {
        $id = DB::table('products_services')
            ->where('tenant_id', $tenantId)
            ->where(function ($q) use ($externalId): void {
                $q->where('external_id', $externalId)
                    ->orWhere('article', $externalId);
            })
            ->value('id');

        return $id !== null ? (int) $id : null;
    }

    private function resolveWarehouseId(int $tenantId, string $externalId): ?int
    {
        if (Schema::hasColumn('warehouses', 'external_id')) {
            $byExternal = DB::table('warehouses')
                ->where('tenant_id', $tenantId)
                ->where('external_id', $externalId)
                ->value('id');
            if ($byExternal !== null) {
                return (int) $byExternal;
            }
        }

        if ($externalId === 'default') {
            $id = DB::table('warehouses')
                ->where('tenant_id', $tenantId)
                ->orderBy('id')
                ->value('id');

            return $id !== null ? (int) $id : null;
        }

        $id = DB::table('warehouses')
            ->where('tenant_id', $tenantId)
            ->where('name', $externalId)
            ->value('id');

        return $id !== null ? (int) $id : null;
    }

    private function recordConflict(
        Stock $stock,
        ?int $importJobId,
        float $incoming,
        float $reserved,
        ?int $userId,
    ): void {
        StockConflict::query()->withoutGlobalScopes()->forceCreate([
            'tenant_id' => $stock->tenant_id,
            'stock_id' => $stock->id,
            'import_job_id' => $importJobId,
            'reason' => 'actual_less_than_reserved_after_import',
            'message' => 'actual_less_than_reserved_after_import',
            'detail' => json_encode([
                'incoming_quantity' => $incoming,
                'current_reserved' => $reserved,
                'status' => 'unresolved',
                'product_id' => $stock->product_id,
                'warehouse_id' => $stock->warehouse_id,
            ], JSON_THROW_ON_ERROR),
            'resolved' => false,
        ]);

        AuditLog::write(
            (int) $stock->tenant_id,
            $userId,
            'commerceml2.import.conflict',
            StockConflict::class,
            (int) $stock->id,
            ['actual' => (float) $stock->actual, 'reserved' => $reserved],
            ['actual' => $incoming, 'reserved' => $reserved],
        );
    }
}
