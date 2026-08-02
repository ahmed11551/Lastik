<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

use Autometria\Enums\LoyaltyTierEnum;
use Autometria\Enums\LoyaltyTransactionTypeEnum;
use Autometria\Models\Customer;
use Autometria\Models\LoyaltyTransaction;
use Autometria\Models\Order;
use Autometria\Services\Cash\CashShiftService;
use Autometria\Services\LoyaltyService;
use Autometria\Services\PaymentService;
use Autometria\Services\ReceiptService;
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

    $this->fx = AcceptanceFixture::make('loy-'.uniqid());
    set_current_tenant_id($this->fx->tenant->id);
    actingAs($this->fx->user);

    $this->loyalty = app(LoyaltyService::class);
});

function makeLoyaltyCustomer(object $fx, array $overrides = []): Customer
{
    return Customer::query()->withoutGlobalScopes()->forceCreate(array_merge([
        'tenant_id' => $fx->tenant->id,
        'type' => Customer::TYPE_INDIVIDUAL,
        'name' => 'Loyalty Client',
        'legal_name' => 'Loyalty Client',
        'phone' => '+7900'.substr((string) hrtime(true), -7),
        'bonus_balance' => 0,
        'total_spent' => 0,
        'tier' => LoyaltyTierEnum::BRONZE->value,
        'created_by' => $fx->user->id,
    ], $overrides));
}

it('calculates earn rates by tier', function (): void {
    $bronze = makeLoyaltyCustomer($this->fx, ['tier' => 'BRONZE']);
    $silver = makeLoyaltyCustomer($this->fx, ['tier' => 'SILVER', 'phone' => '+79001110002']);
    $gold = makeLoyaltyCustomer($this->fx, ['tier' => 'GOLD', 'phone' => '+79001110003']);

    expect($this->loyalty->calculateEarnedBonus($bronze, 1000))->toBe(30.0);
    expect($this->loyalty->calculateEarnedBonus($silver, 1000))->toBe(50.0);
    expect($this->loyalty->calculateEarnedBonus($gold, 1000))->toBe(100.0);
});

it('caps bonus spend at 50 percent of receipt and balance', function (): void {
    $customer = makeLoyaltyCustomer($this->fx, ['bonus_balance' => 800]);

    // 50% of 1000 = 500, balance 800 → 500
    expect($this->loyalty->applyBonusSpend($customer, 700, 1000))->toBe(500.0);

    $poor = makeLoyaltyCustomer($this->fx, ['bonus_balance' => 100, 'phone' => '+79001110004']);
    expect($this->loyalty->applyBonusSpend($poor, 700, 1000))->toBe(100.0);
});

it('settles spend and earn atomically on receipt close', function (): void {
    $customer = makeLoyaltyCustomer($this->fx, [
        'bonus_balance' => 800,
        'tier' => LoyaltyTierEnum::SILVER->value,
        'total_spent' => 60_000,
    ]);

    $result = $this->loyalty->settleReceipt(
        $this->fx->tenant->id,
        (int) $customer->id,
        1000.0,
        700.0, // requested — capped to 500 (50%)
        null,
        null,
        $this->fx->user->id,
    );

    expect($result['spend'])->toBe(500.0);
    // earn on payable 500 @ 5% = 25
    expect($result['earn'])->toBe(25.0);
    expect($result['balance'])->toBe(325.0); // 800 - 500 + 25

    $customer->refresh();
    expect((float) $customer->bonus_balance)->toBe(325.0);
    expect((float) $customer->total_spent)->toBe(61_000.0);

    $tx = LoyaltyTransaction::query()->withoutGlobalScopes()
        ->where('customer_id', $customer->id)
        ->orderBy('id')
        ->get();
    expect($tx)->toHaveCount(2);
    expect($tx[0]->type)->toBe(LoyaltyTransactionTypeEnum::SPEND->value);
    expect($tx[1]->type)->toBe(LoyaltyTransactionTypeEnum::EARN->value);
});

it('upgrades tier based on total spent thresholds', function (): void {
    $customer = makeLoyaltyCustomer($this->fx, [
        'tier' => LoyaltyTierEnum::BRONZE->value,
        'total_spent' => 49_000,
        'bonus_balance' => 0,
    ]);

    $this->loyalty->settleReceipt(
        $this->fx->tenant->id,
        (int) $customer->id,
        2_000.0,
        0,
        null,
        null,
        $this->fx->user->id,
    );

    $customer->refresh();
    expect($customer->tier)->toBe(LoyaltyTierEnum::SILVER->value);
    // earn 3% of 2000 while still bronze at settle time
    expect((float) $customer->bonus_balance)->toBe(60.0);
});

it('settles loyalty via ReceiptService when payment closes order', function (): void {
    $customer = makeLoyaltyCustomer($this->fx, [
        'bonus_balance' => 300,
        'tier' => LoyaltyTierEnum::BRONZE->value,
    ]);

    app(CashShiftService::class)->open(
        $this->fx->tenant->id,
        $this->fx->location->id,
        $this->fx->user->id,
        0,
    );

    $order = Order::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->fx->tenant->id,
        'customer_id' => $customer->id,
        'location_id' => $this->fx->location->id,
        'status' => 'created',
        'payment_status' => 'unpaid',
        'total' => 1000.0,
        'created_by' => $this->fx->user->id,
    ]);

    app(PaymentService::class)->accept(
        $this->fx->tenant->id,
        (int) $order->id,
        [['method' => 'cash', 'amount' => 1000]],
        $this->fx->user->id,
        null,
        600.0, // requested spend → cap 500
    );

    $customer->refresh();
    // spend capped to balance 300; earn 3% of (1000-300)=21
    expect((float) $customer->bonus_balance)->toBe(21.0);
    expect((float) $customer->total_spent)->toBe(1000.0);
});

it('rejects overspend beyond balance through settle (no negative balance)', function (): void {
    // applyBonusSpend already caps; settle should never go negative
    $customer = makeLoyaltyCustomer($this->fx, ['bonus_balance' => 50]);
    $result = $this->loyalty->settleReceipt(
        $this->fx->tenant->id,
        (int) $customer->id,
        1000.0,
        9999.0,
        null,
        null,
        $this->fx->user->id,
    );
    expect($result['spend'])->toBe(50.0);
    expect($result['balance'])->toBeGreaterThanOrEqual(0);
});

it('searches and registers customers via api (pos)', function (): void {
    makeLoyaltyCustomer($this->fx, [
        'name' => 'Иван Тест',
        'phone' => '+79005550101',
        'discount_card_number' => 'CARD-101',
    ]);

    getJson('/api/v1/customers?phone=79005550101')->assertOk()
        ->assertJsonPath('data.0.phone', '+79005550101');

    getJson('/api/v1/customers?card=CARD-101')->assertOk()
        ->assertJsonPath('data.0.discount_card_number', 'CARD-101');

    postJson('/api/v1/customers', [
        'name' => 'Новый Клиент',
        'phone' => '+79005550999',
    ])->assertCreated()
        ->assertJsonPath('data.tier', 'BRONZE')
        ->assertJsonPath('data.bonus_balance', 0);
});

it('calculates loyalty preview via api', function (): void {
    $customer = makeLoyaltyCustomer($this->fx, [
        'bonus_balance' => 1000,
        'tier' => LoyaltyTierEnum::GOLD->value,
    ]);

    postJson('/api/v1/loyalty/calculate', [
        'customer_id' => $customer->id,
        'cart_total' => 2000,
        'requested_spend' => 1500,
    ])->assertOk()
        ->assertJsonPath('data.max_spend', 1000)
        ->assertJsonPath('data.spend', 1000)
        ->assertJsonPath('data.earn', 100); // 10% of (2000-1000)
});

it('exposes loyalty transaction history for crm ui', function (): void {
    $customer = makeLoyaltyCustomer($this->fx, ['bonus_balance' => 100]);
    $this->loyalty->settleReceipt(
        $this->fx->tenant->id,
        (int) $customer->id,
        1000.0,
        100.0,
        null,
        null,
        $this->fx->user->id,
    );

    getJson('/api/v1/loyalty/transactions?customer_id='.$customer->id)
        ->assertOk();
    expect(getJson('/api/v1/loyalty/transactions?customer_id='.$customer->id)->json('data'))
        ->toHaveCount(2);

    getJson('/api/v1/customers/'.$customer->id.'/transactions')
        ->assertOk()
        ->assertJsonPath('data.0.type', LoyaltyTransactionTypeEnum::EARN->value);
});

it('settles via ReceiptService::create with cash_due', function (): void {
    $customer = makeLoyaltyCustomer($this->fx, [
        'bonus_balance' => 500,
        'tier' => LoyaltyTierEnum::BRONZE->value,
    ]);

    $order = Order::query()->withoutGlobalScopes()->forceCreate([
        'tenant_id' => $this->fx->tenant->id,
        'customer_id' => $customer->id,
        'location_id' => $this->fx->location->id,
        'status' => 'created',
        'payment_status' => 'unpaid',
        'total' => 1000.0,
        'created_by' => $this->fx->user->id,
    ]);

    $result = app(ReceiptService::class)->create(
        $this->fx->tenant->id,
        $order,
        (int) $customer->id,
        700.0,
        1000.0,
        null,
        $this->fx->user->id,
    );

    expect($result['spend'])->toBe(500.0); // 50% cap
    expect($result['cash_due'])->toBe(500.0);
    expect($result['earn'])->toBe(15.0); // 3% of 500
});

it('isolates customers by tenant (rls)', function (): void {
    makeLoyaltyCustomer($this->fx, ['phone' => '+79006660101', 'name' => 'T1']);

    $other = AcceptanceFixture::make('loy2-'.uniqid());
    set_current_tenant_id($other->tenant->id);
    actingAs($other->user);

    $list = getJson('/api/v1/customers?q=T1');
    $list->assertOk();
    expect($list->json('data'))->toHaveCount(0);
});
