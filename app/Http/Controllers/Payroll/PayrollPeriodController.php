<?php

declare(strict_types=1);

namespace Autometria\Http\Controllers\Payroll;

use Autometria\Http\Controllers\Controller;
use Autometria\Models\PayrollPeriod;
use Autometria\Services\Payroll\PayrollService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class PayrollPeriodController extends Controller
{
    public function __construct(private readonly PayrollService $payroll) {}

    public function index(Request $request): JsonResponse
    {
        return response()->json(['data' => $this->payroll->listPeriods($this->tenantId($request))->map(fn (PayrollPeriod $period) => $this->serialize($period))->values()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'period_from' => ['required', 'date'], 'period_to' => ['required', 'date', 'after_or_equal:period_from']]);
        $period = $this->payroll->createPeriod($this->tenantId($request), $data, $request->user()?->id);
        return response()->json(['data' => $this->serialize($period)], 201);
    }

    public function calculate(Request $request, int $id): JsonResponse
    {
        return $this->action($request, fn () => $this->payroll->calculate($this->tenantId($request), $id, $request->user()?->id));
    }

    public function approve(Request $request, int $id): JsonResponse
    {
        return $this->action($request, fn () => $this->payroll->approve($this->tenantId($request), $id, $request->user()?->id));
    }

    public function pay(Request $request, int $id): JsonResponse
    {
        return $this->action($request, fn () => $this->payroll->markPaid($this->tenantId($request), $id, $request->user()?->id));
    }

    private function action(Request $request, callable $action): JsonResponse
    {
        try {
            return response()->json(['data' => $this->serialize($action())]);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    private function serialize(PayrollPeriod $period): array
    {
        return ['id' => $period->id, 'name' => $period->name, 'period_from' => $period->period_from?->toDateString(), 'period_to' => $period->period_to?->toDateString(), 'status' => $period->status, 'total_gross' => (float) $period->total_gross, 'total_deductions' => (float) $period->total_deductions, 'total_net' => (float) $period->total_net, 'paid_at' => $period->paid_at?->toISOString()];
    }

    private function tenantId(Request $request): int
    {
        $id = (int) ($request->user()?->tenant_id ?? tenant_id() ?? 0);
        abort_unless($id > 0, 422, 'Tenant context required');
        return $id;
    }
}
