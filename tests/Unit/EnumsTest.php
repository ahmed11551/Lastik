<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 */

declare(strict_types=1);

use Autometria\Enums\OrderStatusEnum;
use Autometria\Enums\PaymentTypeEnum;
use Autometria\Enums\ShiftStatusEnum;

it('has expected enum values', function (): void {
    expect(OrderStatusEnum::cases())->toHaveCount(5);
    expect(ShiftStatusEnum::cases())->toHaveCount(2);
    expect(PaymentTypeEnum::cases())->toHaveCount(4);
});
