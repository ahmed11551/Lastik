<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright  (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author     Себиев Ахмед Сулейманович
 * @license    Proprietary & Confidential.
 */

declare(strict_types=1);

namespace Autometria\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Dashboard', [
            'currentShiftOpen' => true,
            'shiftStartedAt' => now()->subHours(3)->toIso8601String(),
            'shiftRevenue' => 184500,
        ]);
    }
}
