<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\Import\ImportCustomersService;
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
