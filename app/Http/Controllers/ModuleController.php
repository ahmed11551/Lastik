<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Module;
use App\Services\ModuleService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ModuleController extends Controller
{
    public function __construct(
        private readonly ModuleService $modules,
    ) {}

    public function index(Request $request): array
    {
        $tenantId = (int) $request->user()->tenant_id;

        return [
            'data' => Module::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->orderBy('slug')
                ->get(),
        ];
    }

    public function enable(Request $request, string $slug): JsonResponse
    {
        $module = $this->modules->enable(
            (int) $request->user()->tenant_id,
            $slug,
            (int) $request->user()->id,
        );

        return response()->json(['data' => $module]);
    }

    public function disable(Request $request, string $slug): JsonResponse
    {
        $module = $this->modules->disable(
            (int) $request->user()->tenant_id,
            $slug,
            (int) $request->user()->id,
        );

        return response()->json(['data' => $module]);
    }
}
