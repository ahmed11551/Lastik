<?php

declare(strict_types=1);

namespace App\Services\CommerceML;

use App\DTOs\CommerceML\CatalogItemDTO;
use App\Exceptions\Domain\CommerceMLImportException;
use Illuminate\Support\Facades\DB;

class CommerceMLUpsertService
{
    /**
     * @param  list<CatalogItemDTO>  $items
     */
    public function upsertStocksBatch(array $items): void
    {
        if ($items === []) {
            throw CommerceMLImportException::emptyBatch('Empty batch is not allowed for upsert');
        }

        $now = now();

        $stockRows = [];
        $priceRows = [];

        foreach ($items as $item) {
            $productId = $this->resolveProductId($item);

            $stockRows[] = [
                'tenant_id' => $item->tenantId,
                'warehouse_id' => $item->warehouseId,
                'product_id' => $productId,
                'actual' => (float) $item->actual,
                'reserved' => (float) $item->reserved,
                'available' => max(0.0, (float) $item->actual - (float) $item->reserved),
                'updated_at' => $now,
                'created_at' => $now,
            ];

            if ($item->price !== null) {
                $amount = round((float) $item->price, 2);

                $priceRows[] = [
                    'tenant_id' => $item->tenantId,
                    'product_id' => $productId,
                    'amount' => $amount,
                    'price' => $amount,
                    'updated_at' => $now,
                    'created_at' => $now,
                ];
            }
        }

        DB::transaction(function () use ($stockRows, $priceRows): void {
            DB::table('stocks')->upsert(
                $stockRows,
                ['tenant_id', 'warehouse_id', 'product_id'],
                ['actual', 'reserved', 'available', 'updated_at']
            );

            foreach ($priceRows as $priceRow) {
                DB::table('prices')
                    ->where('tenant_id', $priceRow['tenant_id'])
                    ->where('product_id', $priceRow['product_id'])
                    ->updateOrInsert(
                        [],
                        $priceRow,
                    );
            }
        });
    }

    private function resolveProductId(CatalogItemDTO $item): int
    {
        $sku = trim((string) $item->sku);

        if ($sku === '') {
            throw CommerceMLImportException::invalidXml('Empty SKU for CommerceML item');
        }

        $id = DB::table('products_services')
            ->where('tenant_id', $item->tenantId)
            ->where('external_id', $sku)
            ->value('id');

        if ($id === null) {
            throw CommerceMLImportException::invalidXml('Product not found for SKU: '.$sku);
        }

        return (int) $id;
    }
}
