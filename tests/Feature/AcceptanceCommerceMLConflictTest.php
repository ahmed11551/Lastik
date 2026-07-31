<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 */

declare(strict_types=1);

use Autometria\DTOs\CreateOrderDTO;
use Autometria\Models\AuditLog;
use Autometria\Models\Stock;
use Autometria\Models\StockConflict;
use Autometria\Services\Import\CommerceMLImportService;
use Autometria\Services\OrderService;
use Tests\Support\AcceptanceFixture;

/**
 * Приёмка 49.3 / 19 — CommerceML конфликты резервов.
 */
beforeEach(function (): void {
    $this->fx = AcceptanceFixture::make('cml-'.uniqid());
});

it('preserves reserves and records conflict when import actual is below reserved', function (): void {
    $fx = $this->fx;

    // Резерв 8 из 20
    app(OrderService::class)->create(new CreateOrderDTO(
        tenantId: $fx->tenant->id,
        customerId: $fx->customer->id,
        locationId: $fx->location->id,
        assignedSellerId: $fx->user->id,
        masterId: 0,
        items: [[
            'type' => 'product',
            'product_id' => $fx->product->id,
            'qty' => 8,
            'price' => 1000,
            'warehouse_id' => $fx->warehouse->id,
        ]],
        scenario: 'without_installation',
    ), $fx->user->id);

    $payload = [[
        'external_id' => $fx->product->external_id,
        'warehouses' => [[
            'warehouse' => $fx->warehouse->name,
            'qty' => 3, // меньше reserved=8
        ]],
    ]];

    $path = sys_get_temp_dir().'/lastik_cml_'.uniqid().'.json';
    file_put_contents($path, json_encode($payload, JSON_THROW_ON_ERROR));

    $job = app(CommerceMLImportService::class)->import($path, $fx->tenant->id, $fx->user->id);

    expect($job->summary['conflicts'])->toBe(1);

    $stock = Stock::query()->withoutGlobalScopes()->whereKey($fx->stock->id)->first();
    expect((float) $stock->reserved)->toBe(8.0); // резерв не сброшен
    expect((float) $stock->actual)->toBe(3.0);

    $conflict = StockConflict::query()->withoutGlobalScopes()
        ->where('tenant_id', $fx->tenant->id)
        ->where('stock_id', $fx->stock->id)
        ->first();

    expect($conflict)->not->toBeNull();
    expect($conflict->resolved)->toBeFalse();
    expect($conflict->reason)->toBe('actual_less_than_reserved_after_import');

    expect(
        AuditLog::query()->withoutGlobalScopes()
            ->where('tenant_id', $fx->tenant->id)
            ->where('action', 'commerceml2.import.conflict')
            ->exists()
    )->toBeTrue();

    @unlink($path);
});
