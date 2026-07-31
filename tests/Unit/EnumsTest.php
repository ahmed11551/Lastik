<?php

declare(strict_types=1);

use App\Enums\OrderStatusEnum;
use App\Enums\PaymentTypeEnum;
use App\Enums\ShiftStatusEnum;

it('has expected enum values', function (): void {
    expect(OrderStatusEnum::cases())->toHaveCount(5);
    expect(ShiftStatusEnum::cases())->toHaveCount(2);
    expect(PaymentTypeEnum::cases())->toHaveCount(4);
});
