<?php

declare(strict_types=1);

namespace Autometria\Http\Controllers\Payroll;

use Autometria\Http\Controllers\Controller;
use Autometria\Services\Payroll\PayrollService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class AccrualRuleController extends Controller
{
    public function __construct(private readonly PayrollService $payroll) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->payroll->listAccrualRules($this->tenantId($request))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'type' => ['required', 'in:KPI_PERCENT,FIXED,BONUS'], 'value' => ['required', 'numeric', 'min:0'], 'is_active' => ['sometimes', 'boolean']]);
        $data['is_active'] = $data['is_active'] ?? true;
        return response()->json(['data' => $this->payroll->createAccrualRule($this->tenantId($request), $data)], 201);
    }

    private function tenantId(Request $request): int
    {
        $id = (int) ($request->user()?->tenant_id ?? tenant_id() ?? 0);
        abort_unless($id > 0, 422, 'Tenant context required');
        return $id;
    }
}
