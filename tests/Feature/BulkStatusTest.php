<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @package    Autometria\Core
 * @copyright  (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author     Себиев Ахмед Сулейманович
 * @license    Proprietary & Confidential.
 */

declare(strict_types=1);

use Autometria\Models\Order;
use Illuminate\Support\Str;
use Tests\Support\AcceptanceFixture;
use function Pest\Laravel\actingAs;

beforeEach(function (): void {
    $this->withoutMiddleware();
});

it('updates bulk order status with structured JSON response', function (): void {
    $fx = AcceptanceFixture::make('bulk-status-json-' . uniqid());

    $o1 = Order::query()->withoutGlobalScopes()->create([
        'tenant_id' => $fx->tenant->id,
        'location_id' => $fx->location->id,
        'customer_id' => $fx->customer->id,
        'vehicle_id' => $fx->vehicle->id,
        'number' => 'BULK-' . Str::uuid(),
        'status' => 'pending',
        'payment_status' => 'unpaid',
        'total' => 0,
        'created_by' => $fx->user->id,
    ]);

    set_current_tenant_id($fx->tenant->id);

    $response = actingAs($fx->user)->postJson('/api/v1/orders/bulk-status', [
        'ids' => [$o1->id],
        'status' => 'accepted',
    ]);

    $response->assertOk()
        ->assertJson([
            'success' => true,
            'updated_count' => 1,
            'message' => 'Успешно обновлено 1 записей',
        ]);

    $o1->refresh();
    expect($o1->status)->toBe('completed');
});

it('rejects bulk status with empty ids array', function (): void {
    $fx = AcceptanceFixture::make('bulk-empty-' . uniqid());
    set_current_tenant_id($fx->tenant->id);

    $response = actingAs($fx->user)->postJson('/api/v1/orders/bulk-status', [
        'ids' => [],
        'status' => 'accepted',
    ]);

    $response->assertStatus(422)
        ->assertJsonStructure(['message', 'errors'])
        ->assertJsonPath('errors.ids.0', 'Не выбрана ни одна запись');
});

it('rejects invalid status in bulk operation', function (): void {
    $fx = AcceptanceFixture::make('bulk-invalid-status-' . uniqid());
    set_current_tenant_id($fx->tenant->id);

    $response = actingAs($fx->user)->postJson('/api/v1/orders/bulk-status', [
        'ids' => [99999999],
        'status' => 'nonexistent_status',
    ]);

    $response->assertStatus(422);
});
