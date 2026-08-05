<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

use Autometria\DTOs\CreateOrderDTO;
use Autometria\Models\Price;
use Autometria\Models\ProductService;
use Autometria\Services\OrderService;
use Tests\Support\AcceptanceFixture;

/**
 * Финансовая точность OrderService после перевода на BCMath (Этап 1.1).
 *
 * Доказывает, что итоговая сумма заказа (order.total) свободна от
 * накопления float-погрешности при множестве мелких позиций.
 *
 * Цена берётся из БД (lookupCatalogPrice), поэтому создаём продукты
 * в том же виде, что и AcceptanceFixture (поле warehouse_id принадлежит
 * Stock, не Product), с известными retail-ценами и allowOverdraft.
 */
test('order total is exact under float-hostile accumulation', function (): void {
    $fx = AcceptanceFixture::make('precision-'.uniqid());

    $product = ProductService::query()->withoutGlobalScopes()->create([
        'tenant_id' => $fx->tenant->id,
        'type' => ProductService::TYPE_PRODUCT,
        'article' => 'T-PRC01',
        'external_id' => '1C-PRC01',
        'name' => 'Precision 0.01',
        'brand' => 'Test',
        'unit' => 'шт',
        'is_active' => true,
        'base_price' => 0.01,
    ]);
    Price::query()->withoutGlobalScopes()->create([
        'tenant_id' => $fx->tenant->id,
        'product_id' => $product->id,
        'type' => 'retail',
        'price' => 0.01,
        'cost_price' => 0.01,
        'amount' => 0.01,
    ]);

    $items = [];
    for ($i = 0; $i < 100; $i++) {
        $items[] = [
            'type' => 'product',
            'product_id' => $product->id,
            'qty' => 1,
            'price' => 0.01,
            'warehouse_id' => $fx->warehouse->id,
            'allowOverdraft' => true,
        ];
    }

    $order = app(OrderService::class)->create(
        new CreateOrderDTO($fx->tenant->id, $fx->customer->id, $fx->location->id, $fx->user->id, $fx->master->id, $items, allowOverdraft: true),
        $fx->user->id,
    );

    expect((float) $order->total)->toBe(1.0);
    expect($order->total)->toEqual(1.00);
});

test('order total matches exact line-sum arithmetic', function (): void {
    $fx = AcceptanceFixture::make('precision-kpi-'.uniqid());

    $p199 = ProductService::query()->withoutGlobalScopes()->create([
        'tenant_id' => $fx->tenant->id, 'type' => ProductService::TYPE_PRODUCT,
        'article' => 'T-PRC199', 'external_id' => '1C-PRC199', 'name' => 'Precision 19.99',
        'brand' => 'Test', 'unit' => 'шт', 'is_active' => true, 'base_price' => 19.99,
    ]);
    Price::query()->withoutGlobalScopes()->create([
        'tenant_id' => $fx->tenant->id, 'product_id' => $p199->id, 'type' => 'retail',
        'price' => 19.99, 'cost_price' => 19.99, 'amount' => 19.99,
    ]);

    $p010 = ProductService::query()->withoutGlobalScopes()->create([
        'tenant_id' => $fx->tenant->id, 'type' => ProductService::TYPE_PRODUCT,
        'article' => 'T-PRC010', 'external_id' => '1C-PRC010', 'name' => 'Precision 0.10',
        'brand' => 'Test', 'unit' => 'шт', 'is_active' => true, 'base_price' => 0.10,
    ]);
    Price::query()->withoutGlobalScopes()->create([
        'tenant_id' => $fx->tenant->id, 'product_id' => $p010->id, 'type' => 'retail',
        'price' => 0.10, 'cost_price' => 0.10, 'amount' => 0.10,
    ]);

    $items = [
        [
            'type' => 'product', 'product_id' => $p199->id, 'qty' => 3,
            'price' => 19.99, 'warehouse_id' => $fx->warehouse->id, 'allowOverdraft' => true,
        ],
        [
            'type' => 'product', 'product_id' => $p010->id, 'qty' => 1,
            'price' => 0.10, 'warehouse_id' => $fx->warehouse->id, 'allowOverdraft' => true,
        ],
    ];

    $order = app(OrderService::class)->create(
        new CreateOrderDTO($fx->tenant->id, $fx->customer->id, $fx->location->id, $fx->user->id, $fx->master->id, $items, allowOverdraft: true),
        $fx->user->id,
    );

    // 19.99×3 + 0.10 = 60.07 — строго без float-шума.
    expect((float) $order->total)->toBe(60.07);
});
