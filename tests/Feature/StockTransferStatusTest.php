<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

use Autometria\Enums\StockTransferStatus;
use Autometria\Models\Stock;
use Autometria\Models\StockTransfer;
use Autometria\Models\Warehouse;
use Autometria\Services\StockBatchService;
use Autometria\Services\StockTransferService;
use Tests\Support\AcceptanceFixture;
use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->fx = AcceptanceFixture::make('transfer-'.uniqid());
    set_current_tenant_id($this->fx->tenant->id);
    actingAs($this->fx->user);

    // Create a real destination warehouse for transfers.
    $this->dest = Warehouse::query()->withoutGlobalScopes()->create([
        'tenant_id' => $this->fx->tenant->id,
        'name' => 'Dest-'.uniqid(),
        'is_active' => true,
    ]);

    // Fixture already seeds $fx->stock (actual=20) for this warehouse/product.
    // We rely on that seeded stock for the transfer lifecycle assertions.
});

it('moves through DRAFT -> IN_TRANSIT -> COMPLETED lifecycle', function (): void {
    $svc = app(StockTransferService::class);
    $t = $this->fx->tenant->id;

    $draft = StockTransfer::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $t,
        'product_id' => $this->fx->product->id,
        'from_warehouse_id' => $this->fx->warehouse->id,
        'to_warehouse_id' => $this->dest->id,
        'qty' => 5,
        'reason' => 'Двухфазный тест',
        'created_by' => $this->fx->user->id,
        'status' => StockTransferStatus::DRAFT->value,
    ]);

    $shipped = $svc->ship($t, (int) $draft->id, $this->fx->user->id);
    expect($shipped->status)->toBe(StockTransferStatus::IN_TRANSIT);

    // Source available decreased (reserved), actual unchanged after ship.
    $src = Stock::query()->withoutGlobalScopes()
        ->where('tenant_id', $t)
        ->where('warehouse_id', $this->fx->warehouse->id)
        ->where('product_id', $this->fx->product->id)
        ->first();
    expect((float) $src->reserved)->toBeGreaterThanOrEqual(5.0);

    $received = $svc->receive($t, (int) $draft->id, $this->fx->user->id);
    expect($received->status)->toBe(StockTransferStatus::COMPLETED);

    // Source actual decreased after receive (20 - 5 = 15).
    $src->refresh();
    expect((float) $src->actual)->toBe(15.0);
});

it('rejects receive on non in-transit transfer', function (): void {
    $svc = app(StockTransferService::class);
    $t = $this->fx->tenant->id;

    $draft = StockTransfer::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $t,
        'product_id' => $this->fx->product->id,
        'from_warehouse_id' => $this->fx->warehouse->id,
        'to_warehouse_id' => $this->dest->id,
        'qty' => 5,
        'reason' => 'x',
        'created_by' => $this->fx->user->id,
        'status' => StockTransferStatus::DRAFT->value,
    ]);

    expect(fn () => $svc->receive($t, (int) $draft->id, $this->fx->user->id))
        ->toThrow(InvalidArgumentException::class);
});
