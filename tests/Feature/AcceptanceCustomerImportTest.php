<?php

declare(strict_types=1);

use App\Models\AuditLog;
use App\Models\Customer;
use App\Services\Import\ImportCustomersService;
use Tests\Support\AcceptanceFixture;

/**
 * Приёмка 49.2 — импорт покупателей из Excel/CSV.
 */
beforeEach(function (): void {
    $this->fx = AcceptanceFixture::make('imp-'.uniqid());
});

it('imports customers from csv template and reports duplicates without auto-merge', function (): void {
    $fx = $this->fx;

    Customer::query()->withoutGlobalScopes()->create([
        'tenant_id' => $fx->tenant->id,
        'type' => 'individual',
        'name' => 'Существующий',
        'phone' => '+79001112233',
    ]);

    $csv = <<<'CSV'
type,name,phone,email,inn,kpp,legal_name
individual,Новый Клиент,+79009998877,new@ex.com,,,
individual,Дубль Клиент,+79001112233,dup@ex.com,,,
legal,ООО Ромашка,+74951234567,ooo@ex.com,7701234567,770101001,ООО Ромашка
individual,,bad-phone,not-an-email,,,
CSV;

    $path = sys_get_temp_dir().'/lastik_customers_'.uniqid().'.csv';
    file_put_contents($path, $csv);

    $job = app(ImportCustomersService::class)->import($path, $fx->tenant->id, $fx->user->id);

    expect($job->source)->toBe('excel_customers');
    expect($job->summary['imported'])->toBe(2); // Новый + ООО
    expect($job->summary['duplicates'])->toBe(1);
    expect($job->summary['duplicate_candidates'])->toHaveCount(1);
    expect($job->summary['errors'])->not->toBeEmpty(); // bad row

    expect(
        Customer::query()->withoutGlobalScopes()
            ->where('tenant_id', $fx->tenant->id)
            ->where('phone', '+79009998877')
            ->exists()
    )->toBeTrue();

    // дубль не создал вторую карточку
    expect(
        Customer::query()->withoutGlobalScopes()
            ->where('tenant_id', $fx->tenant->id)
            ->where('phone', '+79001112233')
            ->count()
    )->toBe(1);

    expect(
        AuditLog::query()->withoutGlobalScopes()
            ->where('tenant_id', $fx->tenant->id)
            ->where('action', 'excel_customers.import.finished')
            ->exists()
    )->toBeTrue();

    @unlink($path);
});
