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

use Autometria\Services\Import\ImportCustomersService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CustomerImportController extends Controller
{
    public function __construct(
        private readonly ImportCustomersService $imports,
    ) {}

    public function store(Request $request): JsonResponse
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:csv,txt,json', 'max:10240'],
        ]);

        $uploaded = $request->file('file');
        $path = $uploaded->storeAs('imports', uniqid('customers_', true).'.'.$uploaded->getClientOriginalExtension());

        $absolute = storage_path('app/'.$path);

        // Laravel storeAs may use local disk root
        if (! file_exists($absolute)) {
            $absolute = storage_path('app/private/'.$path);
        }
        if (! file_exists($absolute)) {
            $absolute = $uploaded->getRealPath();
        }

        $job = $this->imports->import(
            (string) $absolute,
            (int) $request->user()->tenant_id,
            (int) $request->user()->id,
        );

        return response()->json([
            'data' => $job,
            'message' => ($job->summary['duplicates'] ?? 0) > 0
                ? 'Импорт завершён: найдены дубли, объединение требует подтверждения'
                : 'Импорт завершён',
        ], 201);
    }
}
