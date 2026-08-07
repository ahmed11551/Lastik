<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

use Autometria\Models\OrderItem;
use Autometria\Models\Price;
use Autometria\Models\ProductService;
use Autometria\Services\Cash\CashShiftService;
use Autometria\Services\Marking\DataMatrixParserService;
use Autometria\Services\StockBatchService;
use Tests\Support\AcceptanceFixture;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\postJson;

beforeEach(function (): void {
    $this->withoutMiddleware();
    config([
        'cache.default' => 'array',
        'services.marking.mock_mode' => true,
    ]);
    putenv('MARKING_MOCK_MODE=true');
    $_ENV['MARKING_MOCK_MODE'] = 'true';
    // Recreate singleton so fromConfig() picks up mock_mode=true.
    app()->forgetInstance(\Autometria\Services\Marking\ChestnyZnakClient::class);
    app()->singleton(
        \Autometria\Services\Marking\ChestnyZnakClient::class,
        fn () => \Autometria\Services\Marking\ChestnyZnakClient::fromConfig(),
    );

    $this->fx = AcceptanceFixture::make('mark-'.uniqid());
    set_current_tenant_id($this->fx->tenant->id);
    actingAs($this->fx->user);

    $this->shift = app(CashShiftService::class)->open(
        $this->fx->tenant->id,
        $this->fx->location->id,
        $this->fx->user->id,
        0,
    );
});

function seedMarkedProduct(object $fx, string $article, float $price, bool $marked = true): ProductService
{
    $product = ProductService::query()->forceCreate([
        'tenant_id' => $fx->tenant->id,
        'type' => 'product',
        'name' => 'Marked '.$article,
        'article' => $article,
        'base_price' => $price,
        'is_active' => true,
        'is_marked' => $marked,
        'marking_type' => $marked ? 'SHOES' : null,
    ]);

    Price::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $fx->tenant->id,
        'product_id' => $product->id,
        'type' => 'retail',
        'price' => $price,
        'amount' => $price,
        'cost_price' => round($price * 0.7, 2),
    ]);

    app(StockBatchService::class)->ingress(
        $fx->tenant->id,
        $fx->warehouse->id,
        $product->id,
        10.0,
        round($price * 0.7, 2),
    );

    return $product;
}

it('checkout fails if marked product lacks marking code', function (): void {
    $product = seedMarkedProduct($this->fx, 'MARK-NO-CODE', 1500.0);

    $res = postJson('/api/v1/pos/checkout', [
        'method' => 'cash',
        'amount_tendered' => 2000,
        'items' => [
            [
                'product_id' => $product->id,
                'qty' => 1,
                'warehouse_id' => $this->fx->warehouse->id,
                'type' => 'product',
            ],
        ],
    ]);

    $res->assertStatus(422);
    expect($res->json('code'))->toBe('MARKING_CODE_REQUIRED');
});

it('datamatrix parser correctly extracts gtin and serial', function (): void {
    $raw = '010460043900001421sN&<3!91800092dGVzdA==';
    $parsed = app(DataMatrixParserService::class)->parse($raw);

    expect($parsed['gtin'])->toBe('04600439000014');
    expect($parsed['serial'])->toBe('sN&<3!');
    expect($parsed['crypto_tail'])->not->toBeNull();

    // GS-delimited form
    $gs = DataMatrixParserService::GS;
    $withGs = '01'.$parsed['gtin'].$gs.'21'.$parsed['serial'].$gs.'91TEST'.$gs.'92TAIL';
    $parsedGs = app(DataMatrixParserService::class)->parse($withGs);
    expect($parsedGs['gtin'])->toBe('04600439000014');
    expect($parsedGs['serial'])->toBe('sN&<3!');
});

it('invalid datamatrix is rejected by chestny znak mock', function (): void {
    $product = seedMarkedProduct($this->fx, 'MARK-FAKE', 1500.0);

    // Valid GS1 shape but not RU 046 / 01046… → mock INVALID
    $fake = '010000000000000021SERIALFAKE91800092xxxx';

    $res = postJson('/api/v1/pos/checkout', [
        'method' => 'cash',
        'amount_tendered' => 2000,
        'items' => [
            [
                'product_id' => $product->id,
                'qty' => 1,
                'warehouse_id' => $this->fx->warehouse->id,
                'type' => 'product',
                'marking_code' => $fake,
            ],
        ],
    ]);

    $res->assertStatus(422);
    expect($res->json('code'))->toBeIn(['MARKING_INVALID', 'InvalidMarkingCodeException']);

    $expired = '010460043900001421EXPIRED91800092dGVzdA==';
    $res2 = postJson('/api/v1/pos/checkout', [
        'method' => 'cash',
        'amount_tendered' => 2000,
        'items' => [
            [
                'product_id' => $product->id,
                'qty' => 1,
                'warehouse_id' => $this->fx->warehouse->id,
                'type' => 'product',
                'marking_code' => $expired,
            ],
        ],
    ]);
    $res2->assertStatus(422);
    expect($res2->json('code'))->toBeIn(['MARKING_EXPIRED', 'InvalidMarkingCodeException']);
});

it('accepts valid mock datamatrix on checkout and stores CIS fields', function (): void {
    $product = seedMarkedProduct($this->fx, 'MARK-OK', 1500.0);

    // Avoid HTML-sensitive serial chars (`&`, `<`) — strip_tags-safe CIS for HTTP path.
    // Parser still covers special GS1 charset in the dedicated parser test above.
    $valid = '010460043900001421SNTEST01X91800092dGVzdA==';
    $ok = postJson('/api/v1/pos/checkout', [
        'method' => 'cash',
        'amount_tendered' => 2000,
        'items' => [
            [
                'product_id' => $product->id,
                'qty' => 1,
                'warehouse_id' => $this->fx->warehouse->id,
                'type' => 'product',
                'marking_code' => $valid,
            ],
        ],
    ]);

    if ($ok->status() !== 201) {
        dump(['status' => $ok->status(), 'body' => $ok->json()]);
    }
    $ok->assertStatus(201);

    $item = OrderItem::query()->withoutGlobalScopes()
        ->where('product_id', $product->id)
        ->latest('id')
        ->first();

    expect($item)->not->toBeNull();
    expect($item->marking_code)->toBe($valid);
    expect($item->gtin)->toBe('04600439000014');
    expect($item->serial_number)->toBe('SNTEST01X');
});
