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
use Autometria\Models\Customer;
use Autometria\Models\OneCSyncLog;
use Autometria\Models\Order;
use Autometria\Models\OrderItem;
use Autometria\Models\Payment;
use Autometria\Models\Price;
use Autometria\Models\ProductService;
use Autometria\Models\Stock;
use Autometria\Models\Warehouse;
use Autometria\Services\CommerceML\CommerceMLExportService;
use Autometria\Services\CommerceML\CommerceMLStreamParser;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Tests\Support\AcceptanceFixture;
use function Pest\Laravel\actingAs;

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

/**
 * XXE hardening: внешние сущности/DTD не загружаются и не раскрываются.
 * Парсер либо отклоняет вредоносный файл (бросает исключение при открытии
 * с внешней DTD), либо парсит, но НЕ раскрывает содержимое /etc/passwd.
 */
it('rejects xxe payload', function (): void {
    $xml = <<<XML
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE root [
  <!ENTITY xxe SYSTEM "file:///etc/passwd">
]>
<КоммерческаяИнформация>
  <Каталог>
    <Товары>
      <Товар>
        <Ид>XXE-1</Ид>
        <Наименование>&xxe;</Наименование>
        <Артикул>XXE-A</Артикул>
        <БазоваяЕдиница>шт</БазоваяЕдиница>
        <Цены>
          <Цена>
            <Представление>100</Представление>
            <ЦенаЗаЕдиницу>100</ЦенаЗаЕдиницу>
          </Цена>
        </Цены>
      </Товар>
    </Товары>
  </Каталог>
</КоммерческаяИнформация>
XML;

    $path = sys_get_temp_dir() . '/xxe_test_' . uniqid() . '.xml';
    file_put_contents($path, $xml);

    $leaked = false;
    try {
        $parser = new CommerceMLStreamParser();
        $products = iterator_to_array($parser->parseProducts($path));
        foreach ($products as $p) {
            $name = (string) ($p->name ?? '');
            if (str_contains($name, 'root:') || str_contains($name, ':0:0:')) {
                $leaked = true;
            }
        }
    } catch (Throwable $e) {
        // Парсер корректно отклонил вредоносный файл — XXE заблокирован.
    } finally {
        @unlink($path);
    }

    expect($leaked)->toBeFalse();
});

/**
 * Sprint B: CommerceML 2.09 export — orders.xml + offers.xml + OneCSyncLog.
 */
it('exports orders and offers as valid CommerceML2 xml with sync logs', function (): void {
    $fx = AcceptanceFixture::make('cml-export');
    set_current_tenant_id($fx->tenant->id);

    $customer = Customer::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $fx->tenant->id,
        'type' => Customer::TYPE_LEGAL,
        'name' => 'ООО Тест',
        'legal_name' => 'ООО Тест',
        'inn' => '7701234567',
        'phone' => '+79001112233',
    ]);

    $order = Order::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $fx->tenant->id,
        'customer_id' => $customer->id,
        'location_id' => $fx->location->id,
        'number' => 'ORD-CML-1',
        'status' => 'created',
        'payment_status' => 'paid',
        'total' => 4200.0,
        'created_by' => $fx->user->id,
    ]);

    OrderItem::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $fx->tenant->id,
        'order_id' => $order->id,
        'type' => 'product',
        'product_id' => $fx->product->id,
        'snapshot' => ['name' => $fx->product->name],
        'qty' => 1,
        'price' => 4200,
        'discount' => 0,
    ]);

    Payment::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $fx->tenant->id,
        'order_id' => $order->id,
        'method' => 'cash',
        'type' => 'sale',
        'status' => 'completed',
        'amount' => 4200,
        'created_by' => $fx->user->id,
    ]);

    $export = app(CommerceMLExportService::class);
    $orders = $export->exportOrders($fx->tenant->id, null, 'test_export');
    $offers = $export->exportOffers($fx->tenant->id, 'test_export');

    expect($orders['count'])->toBeGreaterThanOrEqual(1);
    expect($orders['xml'])->toContain('КоммерческаяИнформация');
    expect($orders['xml'])->toContain('ВерсияСхемы="2.09"');
    expect($orders['xml'])->toContain('Документ');
    expect($orders['xml'])->toContain('ORD-'.$order->id);
    expect($orders['xml'])->toContain('Статус заказа');
    expect($orders['xml'])->toContain('Контрагент');

    expect($offers['xml'])->toContain('ПакетПредложений');
    expect($offers['xml'])->toContain('Предложение');
    expect($offers['xml'])->toContain('Остатки');

    $logOrders = OneCSyncLog::query()->withoutGlobalScopes()->find($orders['log']->id);
    $logOffers = OneCSyncLog::query()->withoutGlobalScopes()->find($offers['log']->id);
    expect($logOrders)->not->toBeNull();
    expect($logOrders->direction)->toBe('outbound');
    expect($logOrders->status)->toBe('done');
    expect((int) $logOrders->payload_size)->toBeGreaterThan(0);
    expect($logOffers->status)->toBe('done');
});

it('pushes and pulls via api and writes OneCSyncLog', function (): void {
    $this->withoutMiddleware();

    $fx = AcceptanceFixture::make('cml-api');
    set_current_tenant_id($fx->tenant->id);

    $push = actingAs($fx->user)->postJson('/api/v1/1c/push');
    $push->assertOk();
    expect((int) $push->json('data.orders.log_id'))->toBeGreaterThan(0);
    expect((int) $push->json('data.offers.log_id'))->toBeGreaterThan(0);

    expect(
        OneCSyncLog::query()->withoutGlobalScopes()
            ->where('tenant_id', $fx->tenant->id)
            ->where('direction', 'outbound')
            ->where('status', 'done')
            ->count()
    )->toBeGreaterThanOrEqual(2);

    $pull = actingAs($fx->user)->postJson('/api/v1/1c/pull');
    $pull->assertOk();
    $pull->assertJsonPath('data.ready', true);
    $pullLogId = (int) $pull->json('data.log_id');
    expect($pullLogId)->toBeGreaterThan(0);
    expect(
        OneCSyncLog::query()->withoutGlobalScopes()->where('id', $pullLogId)->value('direction')
    )->toBe('inbound');

    $xml = actingAs($fx->user)->get('/api/v1/1c/export/orders');
    $xml->assertOk();
    expect($xml->headers->get('Content-Type'))->toContain('xml');
    expect($xml->getContent())->toContain('КоммерческаяИнформация');

    $jsonPush = actingAs($fx->user)->postJson('/api/v1/1c/json/push');
    $jsonPush->assertOk();
    expect((int) $jsonPush->json('data.log_id'))->toBeGreaterThan(0);

    $opts = actingAs($fx->user)->putJson('/api/v1/1c/options', [
        'update_stocks' => true,
        'update_prices' => false,
        'create_products' => true,
        'sync_mode' => 'auto',
        'remote_url' => 'https://1c.example/hs/exchange',
    ]);
    $opts->assertOk();
    $opts->assertJsonPath('data.options.sync_mode', 'auto');

    $logs = actingAs($fx->user)->getJson('/api/v1/1c/sync-logs');
    $logs->assertOk();
    expect($logs->json('data'))->toBeArray();
});

