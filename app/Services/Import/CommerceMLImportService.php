<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Models\ImportJob;
use App\Models\ProductService;
use App\Models\Stock;
use App\Models\StockConflict;
use App\Models\Warehouse;
use App\Support\AuditLog;
use Illuminate\Support\Facades\DB;

class CommerceMLImportService
{
    public function import(string $filePath, int $tenantId, ?int $userId = null): ImportJob
    {
        app()->instance('current_tenant_id', $tenantId);

        $job = ImportJob::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'source' => 'commerceml2',
            'status' => 'processing',
            'created_by' => $userId,
        ]);

        $errors = [];
        $summary = [
            'processed' => 0,
            'updated' => 0,
            'created' => 0,
            'skipped' => 0,
            'conflicts' => 0,
        ];

        $rows = CmlParser::parseRemains($filePath);

        DB::transaction(function () use ($rows, $job, $tenantId, $userId, &$errors, &$summary): void {
            foreach ($rows as $row) {
                $product = ProductService::query()->withoutGlobalScopes()
                    ->where('tenant_id', $tenantId)
                    ->where('external_id', $row['external_id'])
                    ->first();

                if (! $product) {
                    $errors[] = ['external_id' => $row['external_id'], 'message' => 'product not found'];
                    $summary['skipped']++;

                    continue;
                }

                foreach ($row['warehouses'] as $whRow) {
                    $warehouse = Warehouse::query()->withoutGlobalScopes()
                        ->where('tenant_id', $tenantId)
                        ->where('name', $whRow['warehouse'])
                        ->first();

                    if (! $warehouse) {
                        $errors[] = ['warehouse' => $whRow['warehouse'], 'message' => 'warehouse not found'];
                        $summary['skipped']++;

                        continue;
                    }

                    $stock = Stock::query()->withoutGlobalScopes()
                        ->where('tenant_id', $tenantId)
                        ->where('warehouse_id', $warehouse->id)
                        ->where('product_id', $product->id)
                        ->lockForUpdate()
                        ->first();

                    $newQty = (float) $whRow['qty'];

                    if ($stock) {
                        $before = (float) $stock->actual;
                        $reserved = (float) $stock->reserved;

                        // Импорт не трогает резервы; конфликт если actual < reserved
                        if ($newQty + 0.0001 < $reserved) {
                            $this->recordConflict($stock, $job, $newQty, 'actual_less_than_reserved_after_import');
                            $summary['conflicts']++;
                            // Обновляем actual, но фиксируем конфликт
                            $stock->actual = $newQty;
                            $stock->available = max(0, round($newQty - $reserved, 2));
                            $stock->save();

                            AuditLog::write(
                                $tenantId,
                                $userId,
                                'commerceml2.import.conflict',
                                StockConflict::class,
                                (int) $stock->id,
                                ['actual' => $before, 'reserved' => $reserved],
                                ['actual' => $newQty, 'reserved' => $reserved],
                            );
                            $summary['processed']++;

                            continue;
                        }

                        $stock->actual = $newQty;
                        $stock->available = round($newQty - $reserved, 2);
                        $stock->save();
                        $summary['updated']++;

                        AuditLog::write(
                            $tenantId,
                            $userId,
                            'commerceml2.import.update',
                            Stock::class,
                            (int) $stock->id,
                            ['actual' => $before],
                            ['actual' => $stock->actual, 'available' => $stock->available, 'reserved' => $reserved],
                        );
                    } else {
                        $stock = Stock::query()->withoutGlobalScopes()->create([
                            'tenant_id' => $tenantId,
                            'warehouse_id' => $warehouse->id,
                            'product_id' => $product->id,
                            'actual' => $newQty,
                            'reserved' => 0,
                            'available' => $newQty,
                        ]);
                        $summary['created']++;

                        AuditLog::write(
                            $tenantId,
                            $userId,
                            'commerceml2.import.create',
                            Stock::class,
                            (int) $stock->id,
                            [],
                            ['actual' => $newQty],
                        );
                    }

                    $summary['processed']++;
                }
            }

            $job->update([
                'status' => 'finished',
                'summary' => array_merge($summary, ['errors' => $errors]),
                'errors' => $errors,
            ]);
        }, 5);

        return $job->fresh();
    }

    private function recordConflict(Stock $stock, ImportJob $job, float $newActual, string $message): void
    {
        StockConflict::query()->withoutGlobalScopes()->create([
            'tenant_id' => $stock->tenant_id,
            'stock_id' => $stock->id,
            'import_job_id' => $job->id,
            'reason' => $message,
            'message' => $message,
            'detail' => json_encode([
                'actual' => $stock->actual,
                'new_actual' => $newActual,
                'reserved' => $stock->reserved,
                'available' => $stock->available,
            ]),
            'resolved' => false,
        ]);
    }
}
