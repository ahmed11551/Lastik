<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 */

declare(strict_types=1);

use Autometria\DTOs\CommerceML\CatalogItemDTO;
use Autometria\Models\Stock;
use Autometria\Services\CommerceML\CommerceMLUpsertService;
use Tests\Support\AcceptanceFixture;

it('creates stock from catalog batch', function (): void {
    $fx = AcceptanceFixture::make('cml-upsert-'.uniqid());
    $warehouse = $fx->warehouse;
    $product = $fx->product;
    $service = app(CommerceMLUpsertService::class);
    $service->upsertStocksBatch([
        new CatalogItemDTO($fx->tenant->id, $warehouse->id, $product->external_id, 12, 2, 4500),
    ]);

    $stock = Stock::query()->withoutGlobalScopes()->where('tenant_id', $fx->tenant->id)->where('product_id', $product->id)->first();
    expect($stock)->not->toBeNull();
    expect($stock->actual)->toBe('12.000');
    expect($stock->reserved)->toBe('2.000');
    expect($stock->available)->toBe('10.000');
    expect((float) app('db')->table('prices')->where('tenant_id', $fx->tenant->id)->where('product_id', $product->id)->value('amount'))->toBe(4500.0);
});

it('updates stock and price on repeat import', function (): void {
    $fx = AcceptanceFixture::make('cml-repeat-'.uniqid());
    $service = app(CommerceMLUpsertService::class);
    $service->upsertStocksBatch([new CatalogItemDTO($fx->tenant->id, $fx->warehouse->id, $fx->product->external_id, 5, 1, 3200)]);
    $service->upsertStocksBatch([new CatalogItemDTO($fx->tenant->id, $fx->warehouse->id, $fx->product->external_id, 8, 3, 3899)]);

    $stock = Stock::query()->withoutGlobalScopes()->where('tenant_id', $fx->tenant->id)->where('product_id', $fx->product->id)->first();
    expect($stock)->not->toBeNull();
    expect(Stock::query()->withoutGlobalScopes()->where('tenant_id', $fx->tenant->id)->where('product_id', $fx->product->id)->count())->toBe(1);
    expect($stock->actual)->toBe('8.000');
    expect($stock->reserved)->toBe('3.000');
    expect($stock->available)->toBe('5.000');

    expect((float) app('db')->table('prices')->where('tenant_id', $fx->tenant->id)->where('product_id', $fx->product->id)->value('amount'))->toBe(3899.0);
});
