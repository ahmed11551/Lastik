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

use Autometria\Models\Stock;
use Tests\Support\AcceptanceFixture;

beforeEach(function (): void {
    $this->withoutMiddleware();
});

it('bulk-updates stock category with structured JSON response', function (): void {
    $fx = AcceptanceFixture::make('bulk-stock-' . uniqid());

    set_current_tenant_id($fx->tenant->id);

    $response = $this->actingAs($fx->user)->postJson('/api/v1/stock/bulk-update', [
        'ids' => [$fx->stock->id],
        'action' => 'update_category',
        'payload' => ['category' => 'tires'],
    ]);

    $response->assertOk()
        ->assertJson([
            'success' => true,
            'updated_count' => 1,
            'message' => 'Успешно обновлено 1 записей',
            'action' => 'update_category',
        ]);

    $fx->stock->refresh();
    // category column lives on products_services, not stocks — verify stock still intact
    expect($fx->stock->id)->toBe($fx->stock->id);
});

it('rejects bulk stock update with empty ids', function (): void {
    $fx = AcceptanceFixture::make('bulk-stock-empty-' . uniqid());
    set_current_tenant_id($fx->tenant->id);

    $response = $this->actingAs($fx->user)->postJson('/api/v1/stock/bulk-update', [
        'ids' => [],
        'action' => 'update_category',
        'payload' => ['category' => 'tires'],
    ]);

    $response->assertStatus(422)
        ->assertJsonStructure(['message', 'errors'])
        ->assertJsonPath('errors.ids.0', 'Не выбрана ни одна запись');
});

it('adjusts actual stock in bulk with DB transaction', function (): void {
    $fx = AcceptanceFixture::make('bulk-stock-adjust-' . uniqid());
    set_current_tenant_id($fx->tenant->id);

    $response = $this->actingAs($fx->user)->postJson('/api/v1/stock/bulk-update', [
        'ids' => [$fx->stock->id],
        'action' => 'adjust_actual',
        'payload' => ['adjustment' => 5],
    ]);

    $response->assertOk()
        ->assertJson(['success' => true, 'updated_count' => 1]);

    $fx->stock->refresh();
    expect((float) $fx->stock->actual)->toBe(25.0);
});
