<?php

declare(strict_types=1);

namespace App\Services\Import;

use App\Models\Customer;
use App\Models\ImportJob;
use App\Support\AuditLog;
use Illuminate\Support\Facades\DB;

/**
 * Импорт покупателей из CSV/Excel-шаблона (п. 15).
 * Дубли не объединяются автоматически — только отчёт для подтверждения.
 */
class ImportCustomersService
{
    public function __construct(
        private ExcelParser $parser,
        private ValidationRules $rules = new ValidationRules,
    ) {}

    public function import(string $filePath, int $tenantId, ?int $userId = null): ImportJob
    {
        set_current_tenant_id($tenantId);

        $job = ImportJob::query()->withoutGlobalScopes()->create([
            'tenant_id' => $tenantId,
            'source' => 'excel_customers',
            'status' => 'processing',
            'created_by' => $userId,
        ]);

        $errors = [];
        $duplicates = [];
        $summary = [
            'total' => 0,
            'imported' => 0,
            'skipped' => 0,
            'duplicates' => 0,
        ];

        $rows = $this->parser->parseCustomers($filePath);
        $summary['total'] = count($rows);

        foreach ($rows as $index => $row) {
            try {
                $this->processRow($tenantId, $userId, $row, $index, $errors, $duplicates, $summary);
            } catch (\Throwable $e) {
                $errors[] = [
                    'row' => $index + 1,
                    'message' => $e->getMessage(),
                    'data' => $row,
                ];
                $summary['skipped']++;
            }
        }

        $job->update([
            'status' => $errors === [] ? 'finished' : 'finished_with_errors',
            'summary' => array_merge($summary, [
                'errors' => $errors,
                'duplicate_candidates' => $duplicates,
            ]),
            'errors' => $errors,
        ]);

        AuditLog::write(
            $tenantId,
            $userId,
            'excel_customers.import.finished',
            ImportJob::class,
            (int) $job->id,
            [],
            $summary,
        );

        return $job->fresh();
    }

    /**
     * @param  array<int, array<string, mixed>>  $errors
     * @param  array<int, array<string, mixed>>  $duplicates
     * @param  array<string, int>  $summary
     */
    private function processRow(
        int $tenantId,
        ?int $userId,
        array $row,
        int $index,
        array &$errors,
        array &$duplicates,
        array &$summary,
    ): void {
        $rowErrors = $this->rules->validateRow($row);

        if ($rowErrors !== []) {
            foreach ($rowErrors as $field => $message) {
                $errors[] = [
                    'row' => $index + 1,
                    'field' => $field,
                    'message' => $message,
                    'data' => $row,
                ];
            }
            $summary['skipped']++;

            return;
        }

        DB::transaction(function () use ($tenantId, $userId, $row, $index, &$duplicates, &$summary): void {
            $duplicate = $this->findDuplicate($tenantId, $row);

            if ($duplicate !== null) {
                // Не объединяем автоматически (п. 15 / 16.1)
                $duplicates[] = [
                    'row' => $index + 1,
                    'existing_customer_id' => $duplicate->id,
                    'match_by' => ! empty($row['inn']) && $duplicate->inn === $row['inn'] ? 'inn' : 'phone',
                    'data' => $row,
                ];
                $summary['duplicates']++;
                $summary['skipped']++;

                AuditLog::write(
                    $tenantId,
                    $userId,
                    'excel_customers.import.duplicate',
                    Customer::class,
                    (int) $duplicate->id,
                    [],
                    ['phone' => $row['phone'], 'inn' => $row['inn'] ?? null, 'row' => $index + 1],
                );

                return;
            }

            $customer = Customer::query()->withoutGlobalScopes()->create([
                'tenant_id' => $tenantId,
                'type' => $row['type'],
                'name' => $row['name'] !== '' ? $row['name'] : ($row['legal_name'] ?: null),
                'phone' => $row['phone'],
                'email' => $row['email'] !== '' ? $row['email'] : null,
                'inn' => $row['inn'] !== '' ? $row['inn'] : null,
                'kpp' => $row['kpp'] !== '' ? $row['kpp'] : null,
                'legal_name' => $row['legal_name'] !== '' ? $row['legal_name'] : null,
            ]);

            $summary['imported']++;

            AuditLog::write(
                $tenantId,
                $userId,
                'excel_customers.import.created',
                Customer::class,
                (int) $customer->id,
                [],
                ['row' => $index + 1, 'phone' => $row['phone'], 'inn' => $row['inn'] ?? null],
            );
        });
    }

    private function findDuplicate(int $tenantId, array $row): ?Customer
    {
        if (! empty($row['inn'])) {
            $byInn = Customer::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('inn', $row['inn'])
                ->first();

            if ($byInn) {
                return $byInn;
            }
        }

        return Customer::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('phone', $row['phone'])
            ->first();
    }
}
