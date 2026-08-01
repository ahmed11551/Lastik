<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @package    Autometria\Core
 * @copyright  (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author     Себиев Ахмед Сулейманович (Chief Software Architect / Lead Developer)
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

it('rejects bulk status when ids array exceeds 500 elements', function (): void {
    $fx = AcceptanceFixture::make('bulk-limit-' . uniqid());
    set_current_tenant_id($fx->tenant->id);

    $ids = range(1, 501);

    $response = actingAs($fx->user)->postJson('/api/v1/orders/bulk-status', [
        'ids' => $ids,
        'status' => 'accepted',
    ]);

    $response->assertStatus(422)
        ->assertJsonStructure(['message', 'errors'])
        ->assertJsonPath('errors.ids', fn ($val) => is_array($val) && count($val) >= 1);
});

it('rejects bulk stock update when ids array exceeds 500 elements', function (): void {
    $fx = AcceptanceFixture::make('bulk-limit-stock-' . uniqid());
    set_current_tenant_id($fx->tenant->id);

    $ids = range(1, 501);

    $response = actingAs($fx->user)->postJson('/api/v1/stock/bulk-update', [
        'ids' => $ids,
        'action' => 'adjust_actual',
        'payload' => ['adjustment' => 1],
    ]);

    $response->assertStatus(422)
        ->assertJsonStructure(['message', 'errors'])
        ->assertJsonPath('errors.ids', fn ($val) => is_array($val) && count($val) >= 1);
});
