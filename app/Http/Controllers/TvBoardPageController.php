<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Inertia shell for TV Board UI (data loaded client-side from GET /api/v1/tv/board).
 */
final class TvBoardPageController extends Controller
{
    public function __invoke(Request $request): Response
    {
        return Inertia::render('TvBoard/Index', [
            'kiosk' => $request->boolean('kiosk'),
        ]);
    }
}
