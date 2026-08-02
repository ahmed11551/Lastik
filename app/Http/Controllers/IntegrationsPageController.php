<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Http\Controllers;

use Inertia\Inertia;
use Inertia\Response;

final class IntegrationsPageController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Settings/Integrations/Index');
    }
}
