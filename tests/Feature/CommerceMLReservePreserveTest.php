<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 */

declare(strict_types=1);

use Autometria\Models\StockConflict;
use Autometria\Services\Import\CommerceMLImportService;
use Tests\Support\AcceptanceFixture;

test('CommerceML2 import does not overwrite reserved quantity', function (): void {
    $fx = AcceptanceFixture::make('cml-reserve-'.uniqid());
    $product = $fx->product;
    $warehouse = $fx->warehouse;
    $stock = $fx->stock;
    $stock->update(['actual' => 10, 'reserved' => 4, 'available' => 6]);

    $file = sys_get_temp_dir().'/lastik_cml_'.uniqid().'.xml';
    file_put_contents($file, <<<XML
<?xml version="1.0"?>
<КоммерческаяИнформация>
  <Каталог><Товары><Товар><Ид>{$product->external_id}</Ид></Товар></Товары></Каталог>
  <Остатки><Остаток><ИдТовара>{$product->external_id}</ИдТовара><Склад><ИдСклада>{$warehouse->name}</ИдСклада><Количество>2</Количество></Склад></Остаток></Остатки>
</КоммерческаяИнформация>
XML);

    $service = resolve(CommerceMLImportService::class);
    $job = $service->import($file, $fx->tenant->id, $fx->user->id);

    $stock->refresh();

    expect((float) $stock->reserved)->toBe(4.0);

    expect(StockConflict::query()->withoutGlobalScopes()->where('import_job_id', $job->id)->exists())->toBeTrue();

    unlink($file);
});
