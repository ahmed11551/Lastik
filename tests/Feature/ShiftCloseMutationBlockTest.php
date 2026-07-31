<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 */

declare(strict_types=1);

use Autometria\Services\Cash\CashShiftService;
use Tests\Support\AcceptanceFixture;

test('closed shift rejects cash mutations', function (): void {
    $fx = AcceptanceFixture::make('closed-shift-'.uniqid());
    $closed = app(CashShiftService::class)->close($fx->shift);

    expect(fn () => app(CashShiftService::class)->inkasso($closed, 100, 'late cash'))
        ->toThrow(RuntimeException::class);
    expect(fn () => app(CashShiftService::class)->withdrawal($closed, 100, 'late withdrawal'))
        ->toThrow(RuntimeException::class);
});
