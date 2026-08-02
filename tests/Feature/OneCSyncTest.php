<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

use Autometria\Jobs\ProcessCommerceMLCatalogJob;
use Autometria\Jobs\ProcessCommerceMLOffersJob;
use Autometria\Models\Category;
use Autometria\Models\OneCSyncLog;
use Autometria\Models\Price;
use Autometria\Models\ProductService;
use Autometria\Models\Stock;
use Autometria\Models\Warehouse;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use function Pest\Laravel\get;
use function Pest\Laravel\post;

beforeEach(function (): void {
    Config::set('services.one_c.login', '1c_user');
    Config::set('services.one_c.password', '1c_pass');
    Storage::fake('local');
    $this->tenantId = \Autometria\Models\Tenant::query()->forceCreate([
        'name' => 'Test Tenant',
        'slug' => 'test-tenant',
        'timezone' => 'Europe/Moscow',
        'is_active' => true,
    ])->id;
});

const IMPORT_XML = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<КоммерческаяИнформация ВерсияСхемы="2.10">
  <Классификатор>
    <Группы>
      <Группа><Ид>CAT-1</Ид><Наименование>Шины</Наименование></Группа>
      <Группа><Ид>CAT-2</Ид><Наименование>Диски</Наименование></Группа>
    </Группы>
  </Классификатор>
  <Каталог>
    <Товары>
      <Товар>
        <Ид>PROD-1</Ид>
        <Артикул>A-001</Артикул>
        <Наименование>Шина Зимняя</Наименование>
        <Группы><Ид>CAT-1</Ид></Группы>
        <Цены><Цена><ЦенаЗаЕдиницу>4000.00</ЦенаЗаЕдиницу></Цена></Цены>
      </Товар>
      <Товар>
        <Ид>PROD-2</Ид>
        <Артикул>A-002</Артикул>
        <Наименование>Диск Литой</Наименование>
        <Группы><Ид>CAT-2</Ид></Группы>
        <Цены><Цена><ЦенаЗаЕдиницу>2500.00</ЦенаЗаЕдиницу></Цена></Цены>
      </Товар>
    </Товары>
  </Каталог>
</КоммерческаяИнформация>
XML;

const OFFERS_XML = <<<XML
<?xml version="1.0" encoding="utf-8"?>
<КоммерческаяИнформация ВерсияСхемы="2.10">
  <ПакетПредложений>
    <ТипыЦен><ТипЦены><Ид>PRICE-RETAIL</Ид><Наименование>Розничная</Наименование></ТипЦены></ТипыЦен>
    <Предложения>
      <Предложение>
        <Ид>PROD-1</Ид>
        <Цены><Цена><ИдТипаЦены>PRICE-RETAIL</ИдТипаЦены><ЦенаЗаЕдиницу>4200.00</ЦенаЗаЕдиницу></Цена></Цены>
        <Остатки><Остаток><Склад>WH-1</Склад><Количество>12</Количество></Остаток></Остатки>
      </Предложение>
      <Предложение>
        <Ид>PROD-2</Ид>
        <Цены><Цена><ИдТипаЦены>PRICE-RETAIL</ИдТипаЦены><ЦенаЗаЕдиницу>2600.00</ЦенаЗаЕдиницу></Цена></Цены>
        <Остатки><Остаток><Склад>WH-1</Склад><Количество>5</Количество></Остаток></Остатки>
      </Предложение>
    </Предложения>
  </ПакетПредложений>
</КоммерческаяИнформация>
XML;

/**
 * Test 1: базовая сессия авторизации и инициализации (checkauth, init).
 * Проверяем логику аутентификации и контроллер напрямую (без глобального middleware лицензии).
 */
it('passes checkauth and init handshake', function (): void {
    $auth = new \Autometria\Services\OneC\OneCAuthService('1c_user', '1c_pass');

    // Успешная аутентификация -> сессионный токен.
    $token = $auth->authenticate('1c_user', '1c_pass');
    expect($token)->not->toBeNull();
    expect($auth->validateSession($token))->toBeTrue();

    // Неверные креды -> null.
    expect($auth->authenticate('bad', 'bad'))->toBeNull();

    // Контроллер: режим init возвращает параметры обмена.
    $controller = new \Autometria\Http\Controllers\Api\V1\OneC\OneCExchangeController($auth);
    $initRequest = \Illuminate\Http\Request::create('/api/v1/1c/exchange?mode=init', 'GET');
    $initResponse = $controller->handle($initRequest);
    expect($initResponse->getStatusCode())->toBe(200);
    $initBody = $initResponse->getContent();
    expect($initBody)->toContain('zip=no');
    expect($initBody)->toContain('file_limit=10485760');
});

/**
 * Test 2: загрузка import.xml -> категории и товары с external_id.
 */
it('imports catalog (categories + products) with external_id', function (): void {
    $tenantId = $this->tenantId;
    Storage::put('1c_imports/import.xml', IMPORT_XML);

    (new ProcessCommerceMLCatalogJob($tenantId, 'import.xml'))->handle();

    expect(Category::query()->withoutGlobalScopes()->where('external_id', 'CAT-1')->exists())->toBeTrue();
    expect(Category::query()->withoutGlobalScopes()->where('external_id', 'CAT-2')->exists())->toBeTrue();

    $product = ProductService::query()->withoutGlobalScopes()
        ->where('tenant_id', $tenantId)
        ->where('external_id', 'PROD-1')
        ->first();
    expect($product)->not->toBeNull();
    expect($product->name)->toBe('Шина Зимняя');
    expect($product->article)->toBe('A-001');
    $category = \Autometria\Models\Category::query()->withoutGlobalScopes()
        ->where('external_id', 'CAT-1')->first();
    expect($category)->not->toBeNull();
    expect($product->category_id)->toBe($category->id);

    $log = OneCSyncLog::query()->withoutGlobalScopes()->where('file_name', 'import.xml')->first();
    expect($log)->not->toBeNull();
    expect($log->status)->toBe('done');
});

/**
 * Test 3: загрузка offers.xml -> цены и остатки на складе.
 */
it('imports offers (prices + stock) correctly', function (): void {
    $tenantId = $this->tenantId;

    // Подготовим товары и склад из import.xml.
    Storage::put('1c_imports/import.xml', IMPORT_XML);
    (new ProcessCommerceMLCatalogJob($tenantId, 'import.xml'))->handle();

    Warehouse::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $tenantId,
        'name' => 'Склад 1',
        'external_id' => 'WH-1',
        'location_id' => \Autometria\Models\Location::query()->forceCreate([
            'tenant_id' => $tenantId,
            'name' => 'Локация 1',
        ])->id,
    ]);

    Storage::put('1c_imports/offers.xml', OFFERS_XML);
    (new ProcessCommerceMLOffersJob($tenantId, 'offers.xml'))->handle();

    // Цена PROD-1 = 4200 (тип из offers.xml).
    $productId = \Autometria\Models\ProductService::query()->withoutGlobalScopes()
        ->where('external_id', 'PROD-1')->value('id');
    $price = Price::query()->withoutGlobalScopes()
        ->where('tenant_id', $tenantId)
        ->where('product_id', $productId)
        ->first();
    expect($price)->not->toBeNull();
    expect((float) $price->amount)->toBe(4200.0);

    // Остаток PROD-1 на WH-1 = 12.
    $wh = Warehouse::query()->withoutGlobalScopes()->where('external_id', 'WH-1')->first();
    $stock = Stock::query()->withoutGlobalScopes()
        ->where('tenant_id', $tenantId)
        ->where('product_id', $productId)
        ->where('warehouse_id', $wh->id)
        ->first();
    expect($stock)->not->toBeNull();
    expect((float) $stock->actual)->toBe(12.0);
    expect((float) $stock->available)->toBe(12.0);
});
