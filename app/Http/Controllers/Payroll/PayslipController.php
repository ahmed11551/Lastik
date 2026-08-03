<?php

declare(strict_types=1);

namespace Autometria\Http\Controllers\Payroll;

use Autometria\Http\Controllers\Controller;
use Autometria\Models\Payslip;
use Autometria\Services\Payroll\PayrollService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class PayslipController extends Controller
{
    public function __construct(private readonly PayrollService $payroll) {}

    public function index(Request $request): JsonResponse
    {
        $periodId = $request->integer('period_id') ?: null;
        return response()->json(['data' => $this->payroll->listPayslips($this->tenantId($request), $periodId)->map(fn (Payslip $payslip) => $this->serialize($payslip))->values()]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        return response()->json(['data' => $this->serialize($this->payroll->getPayslip($this->tenantId($request), $id), true)]);
    }

    private function serialize(Payslip $payslip, bool $withItems = false): array
    {
        $data = ['id' => $payslip->id, 'payroll_period_id' => $payslip->payroll_period_id, 'user_id' => $payslip->user_id, 'user_name' => $payslip->user?->name, 'status' => $payslip->status, 'gross' => (float) $payslip->gross, 'deductions_total' => (float) $payslip->deductions_total, 'net' => (float) $payslip->net];
        if ($withItems) $data['items'] = $payslip->items->map(fn ($item) => ['id' => $item->id, 'type' => $item->type, 'label' => $item->label, 'amount' => (float) $item->amount, 'source_id' => $item->source_id])->values();
        return $data;
    }

    private function tenantId(Request $request): int
    {
        $id = (int) ($request->user()?->tenant_id ?? tenant_id() ?? 0);
        abort_unless($id > 0, 422, 'Tenant context required');
        return $id;
    }
}
