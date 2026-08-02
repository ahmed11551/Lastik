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

namespace Autometria\Services\Import;

use Autometria\DTOs\CommerceML\StockBalanceDTO;
use Autometria\Models\ImportJob;
use Autometria\Services\CommerceML\CommerceMLBatchUpsertService;
use Autometria\Services\CommerceML\CommerceMLStreamParser;

class CommerceMLImportService
{
    public function __construct(
        private readonly CommerceMLBatchUpsertService $batchUpsert,
        private readonly CommerceMLStreamParser $streamParser,
    ) {}

    /**
     * @param  array{file_name?: string, channel?: string, file_type?: string}|null  $meta
     */
    public function import(string $filePath, int $tenantId, ?int $userId = null, ?array $meta = null): ImportJob
    {
        set_current_tenant_id($tenantId);

        $meta = $meta ?? [];
        $job = ImportJob::query()->withoutGlobalScopes()->forceCreate([
            'tenant_id' => $tenantId,
            'source' => 'commerceml2',
            'file_name' => $meta['file_name'] ?? basename($filePath),
            'channel' => $meta['channel'] ?? 'manual_upload',
            'status' => 'processing',
            'summary' => [
                'file_type' => $meta['file_type'] ?? null,
            ],
            'created_by' => $userId,
        ]);

        try {
            $ext = strtolower(pathinfo($filePath, PATHINFO_EXTENSION));

            if ($ext === 'xml' || $this->looksLikeXml($filePath)) {
                return $this->importXmlStream($filePath, $tenantId, $job, $userId);
            }

            return $this->importJsonRemains($filePath, $tenantId, $job, $userId);
        } catch (\Throwable $e) {
            $job->update([
                'status' => 'failed',
                'error_message' => $e->getMessage(),
                'errors' => [['message' => $e->getMessage()]],
            ]);

            throw $e;
        }
    }

    private function importXmlStream(string $filePath, int $tenantId, ImportJob $job, ?int $userId): ImportJob
    {
        $balances = collect();
        foreach ($this->streamParser->parseOffers($filePath) as $dto) {
            $balances->push($dto);
        }

        $batchSummary = $this->batchUpsert->upsertStockBalances(
            $tenantId,
            $balances,
            (int) $job->id,
            $userId,
        );

        $job->update([
            'status' => 'completed',
            'summary' => array_merge(is_array($job->summary) ? $job->summary : [], $batchSummary, [
                'offers' => (int) ($batchSummary['processed'] ?? 0),
            ]),
            'errors' => [],
            'error_message' => null,
        ]);

        return $job->fresh();
    }

    private function importJsonRemains(string $filePath, int $tenantId, ImportJob $job, ?int $userId): ImportJob
    {
        $rows = CmlParser::parseRemains($filePath);
        $balances = collect();

        foreach ($rows as $row) {
            foreach ($row['warehouses'] as $whRow) {
                $balances->push(new StockBalanceDTO(
                    productExternalId: (string) $row['external_id'],
                    warehouseExternalId: (string) $whRow['warehouse'],
                    quantity: (float) $whRow['qty'],
                ));
            }
        }

        $batchSummary = $this->batchUpsert->upsertStockBalances(
            $tenantId,
            $balances,
            (int) $job->id,
            $userId,
        );

        $summary = [
            'processed' => $batchSummary['processed'],
            'conflicts' => $batchSummary['conflicts'],
            'skipped' => $batchSummary['skipped'],
            'updated' => max(0, $batchSummary['processed'] - $batchSummary['conflicts']),
            'created' => 0,
            'errors' => [],
        ];

        $job->update([
            'status' => 'completed',
            'summary' => array_merge(is_array($job->summary) ? $job->summary : [], $summary, [
                'offers' => (int) ($summary['processed'] ?? 0),
            ]),
            'errors' => [],
            'error_message' => null,
        ]);

        return $job->fresh();
    }

    private function looksLikeXml(string $filePath): bool
    {
        $fh = @fopen($filePath, 'rb');
        if ($fh === false) {
            return false;
        }

        $head = (string) fread($fh, 128);
        fclose($fh);

        return str_contains($head, '<?xml') || str_contains($head, '<КоммерческаяИнформация');
    }
}
