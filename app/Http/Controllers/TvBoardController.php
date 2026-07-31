<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @package    Autometria\Core
 * @copyright  (c) 2026 Себиев Ахмед Сулейманович (Sebiev Akhmed Suleymanovich). All Rights Reserved.
 * @author     Себиев Ахмед Сулейманович (Chief Software Architect / Lead Developer)
 * @license    Proprietary & Confidential. Unauthorized copying, distribution,
 *             modification, or reverse engineering of this file, via any medium,
 *             is strictly prohibited.
 *
 * NOTICE: All information contained herein is, and remains the property of
 * Себиев Ахмед Сулейманович. The intellectual and technical concepts contained
 * herein are proprietary and protected by trade secret and copyright law.
 */
/**
 * LASTIK B2B SaaS Engine Core
 *
 * @copyright  (c) 2026 Себиев Ахмед Сулейманович (Sebiev Akhmed Suleymanovich). All Rights Reserved.
 * @author     Себиев Ахмед Сулейманович (Chief Software Architect / Lead Developer)
 * @license    Proprietary & Confidential. Unauthorized copying, distribution,
 *             modification, or reverse engineering of this file, via any medium,
 *             is strictly prohibited.
 *
 * NOTICE: All information contained herein is, and remains the property of
 * Себиев Ахмед Сулейманович. The intellectual and technical concepts contained
 * herein are proprietary and protected by trade secret and copyright law.
 */
/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 */

declare(strict_types=1);

namespace Autometria\Http\Controllers;

use Autometria\Services\TvBoardService;
use Illuminate\Http\Request;

class TvBoardController extends Controller
{
    public function __construct(
        private readonly TvBoardService $tv,
    ) {}

    public function __invoke(Request $request): array
    {
        $request->validate([
            'tenant_id' => ['prohibited'],
        ]);

        $permissions = $request->user()?->role?->permissions ?? [];
        $canAll = in_array('locations.all', $permissions, true)
            || in_array('admin.dashboard', $permissions, true);

        // Чужую точку через query могут запрашивать только админы с locations.all
        $locationId = location_id();
        if ($canAll && $request->filled('location_id')) {
            $locationId = (int) $request->integer('location_id');
        }

        return [
            'data' => $this->tv->board(
                (int) ($request->user()?->tenant_id ?? tenant_id()),
                $locationId,
            ),
        ];
    }
}
