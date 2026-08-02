<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

use Autometria\Enums\EgaisDocTypeEnum;
use Autometria\Enums\MarkingCodeStatusEnum;
use Autometria\Exceptions\Domain\InvalidMarkingCodeException;
use Autometria\Models\AuditLog;
use Autometria\Models\EgaisDocument;
use Autometria\Models\MarkingCode;
use Autometria\Models\Order;
use Autometria\Models\OrderItem;
use Autometria\Models\Price;
use Autometria\Models\ProductService;
use Autometria\Services\Cash\CashShiftService;
use Autometria\Services\Fiscal\FiscalReceiptService;
use Autometria\Services\Marking\EgaisService;
use Autometria\Services\Marking\MarkingValidationService;
use Autometria\Services\StockBatchService;
use Tests\Support\AcceptanceFixture;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

beforeEach(function (): void {
    $this->withoutMiddleware([
        \Autometria\Http\Middleware\EnsurePermission::class,
        \Autometria\Http\Middleware\EnforceLocationAccess::class,
    ]);
    config(['cache.default' => 'array']);
    putenv('MARKING_MOCK_MODE=true');
    $_ENV['MARKING_MOCK_MODE'] = 'true';

    $this->fx = AcceptanceFixture::make('reg-'.uniqid());
    set_current_tenant_id($this->fx->tenant->id);
    actingAs($this->fx->user);

    $this->marking = app(MarkingValidationService::class);
    $this->egais = app(EgaisService::class);
});

function validCis(string $serial = 'ABC123XYZ'): string
{
    return '010460043900001421'.$serial;
}

it('validates DataMatrix structure and registers local APPLIED code', function (): void {
    $parsed = $this->marking->validateDataMatrix(validCis('SER001'));

    expect($parsed['gtin'])->toBe('04600439000014');
    expect($parsed['serial'])->toBe('SER001');
    expect($parsed['status'])->toBe(MarkingCodeStatusEnum::APPLIED->value);

    $row = MarkingCode::query()->withoutGlobalScopes()->find($parsed['marking_code_id']);
    expect($row)->not->toBeNull();
    expect($row->gtin)->toBe('04600439000014');
});

it('blocks double exit of the same marking code', function (): void {
    $code = validCis('DBL001');
    $this->marking->validateDataMatrix($code);
    $this->marking->registerMarkSelling($code);

    expect(fn () => $this->marking->registerMarkSelling($code))
        ->toThrow(InvalidMarkingCodeException::class);

    expect(fn () => $this->marking->validateDataMatrix($code))
        ->toThrow(InvalidMarkingCodeException::class);

    $status = MarkingCode::query()->withoutGlobalScopes()
        ->where('code', $code)
        ->value('status');
    expect($status)->toBe(MarkingCodeStatusEnum::SOLD->value);
});

it('creates EGAIS unseal act as DRAFT', function (): void {
    $product = ProductService::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->fx->tenant->id,
        'type' => 'product',
        'name' => 'Пиво кег',
        'article' => 'EGAIS-'.uniqid(),
        'base_price' => 500,
        'is_active' => true,
        'is_egais' => true,
        'egais_alcocode' => '0034567890123456789',
    ]);

    $doc = $this->egais->createEgaisUnsealAct((int) $product->id, 0.5, '030000000001');

    expect($doc->doc_type)->toBe(EgaisDocTypeEnum::UNSEAL->value);
    expect($doc->status)->toBe('DRAFT');
    expect($doc->fsrar_id)->toBe('030000000001');
    expect((float) $doc->payload['volume'])->toBe(0.5);

    $api = postJson('/api/v1/regulatory/egais/unseal', [
        'product_id' => $product->id,
        'volume' => 0.25,
        'fsrar_id' => '030000000001',
    ]);
    $api->assertStatus(201);
    expect(EgaisDocument::query()->withoutGlobalScopes()->where('tenant_id', $this->fx->tenant->id)->count())->toBe(2);
});

it('embeds fiscal marking tags 1162/1163 into sale snapshot', function (): void {
    $order = Order::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->fx->tenant->id,
        'location_id' => $this->fx->location->id,
        'customer_id' => $this->fx->customer->id,
        'number' => 'REG-'.uniqid(),
        'status' => 'new',
        'payment_status' => 'paid',
        'total' => 1000,
        'scenario' => 'without_installation',
    ]);

    OrderItem::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->fx->tenant->id,
        'order_id' => $order->id,
        'product_id' => $this->fx->product->id,
        'type' => 'product',
        'qty' => 1,
        'price' => 1000,
        'discount' => 0,
        'marking_code' => validCis('FIS001'),
        'gtin' => '04600439000014',
        'serial_number' => 'FIS001',
        'snapshot' => ['name' => 'Marked shoe'],
    ]);

    $order->load('orderItems.product');
    $snapshot = app(FiscalReceiptService::class)->buildSaleSnapshot($order);

    expect($snapshot['items'][0])->toHaveKey('fiscal_tags');
    expect($snapshot['items'][0]['fiscal_tags'])->toHaveKey('1162');
    expect($snapshot['items'][0]['fiscal_tags'])->toHaveKey('1163');
    expect($snapshot['items'][0]['product_code'])->toHaveKey('gs1m');
    expect($snapshot['items'][0]['gtin'])->toBe('04600439000014');
});

it('verifies CIS via API and lists marking codes registry', function (): void {
    $verify = postJson('/api/v1/regulatory/marking/verify', [
        'code' => validCis('API001'),
        'product_id' => $this->fx->product->id,
    ]);
    $verify->assertOk()->assertJsonPath('data.valid', true);

    $list = getJson('/api/v1/regulatory/marking/codes');
    $list->assertOk();
    expect(count($list->json('data')))->toBeGreaterThan(0);
});

it('isolates marking codes by tenant (rls)', function (): void {
    $this->marking->validateDataMatrix(validCis('RLS001'));

    $other = AcceptanceFixture::make('reg2-'.uniqid());
    set_current_tenant_id($other->tenant->id);
    actingAs($other->user);

    getJson('/api/v1/regulatory/marking/codes')->assertOk();
    expect(getJson('/api/v1/regulatory/marking/codes')->json('data'))->toHaveCount(0);
});

it('registers mark selling on POS checkout and blocks reuse', function (): void {
    $product = ProductService::query()->forceCreate([
        'tenant_id' => $this->fx->tenant->id,
        'type' => 'product',
        'name' => 'Marked POS',
        'article' => 'MP-'.uniqid(),
        'base_price' => 1000,
        'is_active' => true,
        'is_marked' => true,
        'marking_type' => 'SHOES',
    ]);
    Price::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->fx->tenant->id,
        'product_id' => $product->id,
        'type' => 'retail',
        'price' => 1000,
        'amount' => 1000,
        'cost_price' => 500,
    ]);
    app(StockBatchService::class)->ingress(
        $this->fx->tenant->id,
        $this->fx->warehouse->id,
        $product->id,
        5,
        500,
        'B-REG',
    );

    app(CashShiftService::class)->open(
        $this->fx->tenant->id,
        $this->fx->location->id,
        $this->fx->user->id,
        0,
    );

    $cis = validCis('POS99X');
    $res = postJson('/api/v1/pos/checkout', [
        'method' => 'cash',
        'amount_tendered' => 1100,
        'items' => [[
            'product_id' => $product->id,
            'qty' => 1,
            'warehouse_id' => $this->fx->warehouse->id,
            'type' => 'product',
            'marking_code' => $cis,
        ]],
    ]);
    $res->assertStatus(201);

    $status = MarkingCode::query()->withoutGlobalScopes()
        ->where('code', $cis)
        ->value('status');
    expect($status)->toBe(MarkingCodeStatusEnum::SOLD->value);

    $reuse = postJson('/api/v1/pos/checkout', [
        'method' => 'cash',
        'amount_tendered' => 1100,
        'items' => [[
            'product_id' => $product->id,
            'qty' => 1,
            'warehouse_id' => $this->fx->warehouse->id,
            'type' => 'product',
            'marking_code' => $cis,
        ]],
    ]);
    $reuse->assertStatus(422);
    expect($reuse->json('code'))->toBe('MARKING_ALREADY_SOLD');
});

it('writes AuditLog on marking validate and sell', function (): void {
    $code = validCis('AUD001');
    $parsed = $this->marking->validateDataMatrix($code);

    expect(
        AuditLog::query()->withoutGlobalScopes()
            ->where('tenant_id', $this->fx->tenant->id)
            ->where('action', 'marking.validated')
            ->where('object_id', $parsed['marking_code_id'])
            ->exists()
    )->toBeTrue();

    $this->marking->registerMarkSelling($code);

    expect(
        AuditLog::query()->withoutGlobalScopes()
            ->where('tenant_id', $this->fx->tenant->id)
            ->where('action', 'marking.sold')
            ->exists()
    )->toBeTrue();
});
