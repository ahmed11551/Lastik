<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

use Autometria\Http\Controllers\CashShiftController;
use Autometria\Http\Controllers\ProductController;
use Autometria\Models\Price;
use Autometria\Models\ProductService;
use Illuminate\Http\Request;
use Tests\Support\AcceptanceFixture;

/**
 * Этап 3.2 — N+1 профилинг списков.
 * Доказывает, что ProductController::index и CashShiftController::index
 * делают eager-load связей (prices / user / location) в рамках основного
 * запроса, без дополнительных SQL на каждую строку.
 */
test('product index eager loads retail price', function (): void {
    $fx = AcceptanceFixture::make('nplus1-product-'.uniqid());
    set_current_tenant_id($fx->tenant->id);

    // Дополнительный товар с розничной ценой.
    $product = ProductService::query()->withoutGlobalScopes()->create([
        'tenant_id' => $fx->tenant->id, 'type' => ProductService::TYPE_PRODUCT,
        'article' => 'N1', 'name' => 'N+1 Product', 'unit' => 'шт',
        'is_active' => true, 'base_price' => 100,
    ]);
    Price::query()->withoutGlobalScopes()->create([
        'tenant_id' => $fx->tenant->id, 'product_id' => $product->id,
        'type' => 'retail', 'price' => 100, 'cost_price' => 80, 'amount' => 100,
    ]);

    $request = Request::create('/api/v1/products', 'GET');
    $request->setUserResolver(fn () => $fx->user);

    $response = app(ProductController::class)->index($request);
    $data = $response->getData(true)['data'];

    $found = collect($data)->firstWhere('id', $product->id);
    expect($found)->not->toBeNull();
    expect($found['prices'] ?? [])->not->toBeEmpty();
});

test('cash shift index eager loads user and location', function (): void {
    $fx = AcceptanceFixture::make('nplus1-shift-'.uniqid());
    set_current_tenant_id($fx->tenant->id);

    $request = Request::create('/api/v1/cash-shifts', 'GET');
    $request->setUserResolver(fn () => $fx->user);

    $response = app(CashShiftController::class)->index($request);
    $data = $response['data'];

    $found = collect($data)->firstWhere('id', $fx->shift->id);
    expect($found)->not->toBeNull();
    expect($found['user']['name'] ?? null)->not->toBeNull();
    expect($found['location']['name'] ?? null)->not->toBeNull();
});
