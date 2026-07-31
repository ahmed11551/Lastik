<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 */

declare(strict_types=1);

use Autometria\DTOs\CommerceML\CommerceMLProductDTO;
use Autometria\DTOs\CommerceML\StockBalanceDTO;
use Autometria\DTOs\CreateOrderDTO;
use Autometria\Models\Stock;
use Autometria\Models\StockConflict;
use Autometria\Services\CommerceML\CommerceMLBatchUpsertService;
use Autometria\Services\CommerceML\CommerceMLStreamParser;
use Autometria\Services\OrderService;
use Tests\Support\AcceptanceFixture;

it('parses CommerceML product nodes into typed DTOs', function (): void {
    $xml = <<<'XML'
    <?xml version="1.0" encoding="UTF-8"?>
    <КоммерческаяИнформация>
      <Каталог>
        <Товар>
          <Ид>1C-ABC</Ид>
          <Артикул>SKU-1</Артикул>
          <Наименование>Шина Test</Наименование>
          <Описание>desc</Описание>
          <Группы><Ид>GRP-1</Ид></Группы>
          <БазоваяЕдиница>шт</БазоваяЕдиница>
        </Товар>
      </Каталог>
    </КоммерческаяИнформация>
    XML;

    $path = sys_get_temp_dir().'/cml_products_'.uniqid().'.xml';
    file_put_contents($path, $xml);

    $products = iterator_to_array(app(CommerceMLStreamParser::class)->parseProducts($path));
    expect($products)->toHaveCount(1);
    expect($products[0])->toBeInstanceOf(CommerceMLProductDTO::class);
    expect($products[0]->externalId)->toBe('1C-ABC');
    expect($products[0]->sku)->toBe('SKU-1');
    expect($products[0]->name)->toBe('Шина Test');

    @unlink($path);
});

it('batch upserts stock balances and records reserve conflicts', function (): void {
    $fx = AcceptanceFixture::make('cml-batch-'.uniqid());
    $fx->warehouse->forceFill(['external_id' => 'WH-1'])->save();

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
            'warehouse_id' => $fx->warehouse->id,
        ]],
        scenario: 'without_installation',
    ), $fx->user->id);

    $summary = app(CommerceMLBatchUpsertService::class)->upsertStockBalances(
        $fx->tenant->id,
        collect([
            new StockBalanceDTO(
                productExternalId: (string) $fx->product->external_id,
                warehouseExternalId: 'WH-1',
                quantity: 3.0,
                price: 5100,
            ),
        ]),
        null,
        $fx->user->id,
    );

    expect($summary['conflicts'])->toBe(1);
    expect($summary['processed'])->toBe(1);

    $stock = Stock::query()->withoutGlobalScopes()->whereKey($fx->stock->id)->first();
    expect((float) $stock->reserved)->toBe(8.0);
    expect((float) $stock->actual)->toBe(3.0);

    expect(
        StockConflict::query()->withoutGlobalScopes()
            ->where('tenant_id', $fx->tenant->id)
            ->where('stock_id', $fx->stock->id)
            ->exists()
    )->toBeTrue();

    expect((float) app('db')->table('prices')
        ->where('tenant_id', $fx->tenant->id)
        ->where('product_id', $fx->product->id)
        ->value('amount'))->toBe(5100.0);
});
