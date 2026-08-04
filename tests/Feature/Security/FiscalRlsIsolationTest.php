<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 */

declare(strict_types=1);

use Autometria\Enums\FiscalReceiptStatus;
use Autometria\Enums\FiscalReceiptType;
use Autometria\Models\FiscalReceipt;
use Autometria\Models\Tenant;
use Illuminate\Support\Facades\DB;

/**
 * Gate P0 / Hermes — изоляция фискальных чеков (fiscal_receipts) и всего хвоста tenant-таблиц.
 * Проверяет: (1) модельная изоляция под set_current_tenant_id, (2) наличие RLS-политики в БД,
 * (3) установку контекста воркером FiscalizeReceiptJob перед raw-SQL.
 */
beforeEach(function (): void {
    config(['cache.default' => 'array']);
    $this->tenantA = Tenant::create(['name' => 'FRLS-A'.uniqid(), 'slug' => 'frls-a-'.uniqid()]);
    $this->tenantB = Tenant::create(['name' => 'FRLS-B'.uniqid(), 'slug' => 'frls-b-'.uniqid()]);
});

afterEach(function (): void {
    // Tenants НЕ удаляем (каскадный delete бьёт append-only audit_logs триггер).
    // Slug уникален (uniqid), коллизий с другими тестами нет.
});

it('isolates fiscal receipts by tenant scope', function (): void {
    $receiptA = FiscalReceipt::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->tenantA->id,
        'operation' => FiscalReceiptType::SELL->value,
        'status' => FiscalReceiptStatus::PENDING->value,
        'total_amount' => 100,
        'payload_snapshot' => '{}',
        'idempotency_key' => 'test-'.uniqid(),
        'driver_request_id' => \Illuminate\Support\Str::uuid()->toString(),
    ]);
    FiscalReceipt::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->tenantB->id,
        'operation' => FiscalReceiptType::SELL->value,
        'status' => FiscalReceiptStatus::PENDING->value,
        'total_amount' => 200,
        'payload_snapshot' => '{}',
        'idempotency_key' => 'test-'.uniqid(),
        'driver_request_id' => \Illuminate\Support\Str::uuid()->toString(),
    ]);

    set_current_tenant_id($this->tenantA->id);

    expect(FiscalReceipt::query()->whereKey($receiptA->id)->first())->not->toBeNull();
    expect(FiscalReceipt::query()->count())->toBe(1);
});

it('has RLS policy on fiscal_receipts reading app.current_tenant_id', function (): void {
    $row = DB::selectOne(
        "SELECT qual FROM pg_policies WHERE schemaname='public' AND tablename='fiscal_receipts' AND policyname='tenant_isolation_fiscal_receipts'"
    );
    expect($row)->not->toBeNull();
    expect($row->qual)->toContain("app.current_tenant_id");
});

it('fiscalize worker sets tenant context before raw SQL', function (): void {
    $receipt = FiscalReceipt::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->tenantA->id,
        'operation' => FiscalReceiptType::SELL->value,
        'status' => FiscalReceiptStatus::PENDING->value,
        'total_amount' => 100,
        'payload_snapshot' => '{}',
        'idempotency_key' => 'test-worker-'.uniqid(),
        'driver_request_id' => \Illuminate\Support\Str::uuid()->toString(),
    ]);

    // Запуск handle() напрямую: не должен упасть из-за RLS при установке контекста.
    $job = new \Autometria\Jobs\FiscalizeReceiptJob($receipt->id, $receipt->tenant_id);
    expect(fn () => $job->handle(new \Autometria\Services\Fiscal\FiscalReceiptService()))
        ->not->toThrow(\Throwable::class);

    // Контекст установлен корректно.
    $ctx = DB::selectOne("SELECT current_setting('app.current_tenant_id', true) AS v");
    expect((string) $ctx->v)->toBe((string) $this->tenantA->id);
});
