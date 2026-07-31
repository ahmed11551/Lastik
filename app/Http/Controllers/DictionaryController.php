<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Dictionary;
use App\Services\DictionaryService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DictionaryController extends Controller
{
    public function __construct(
        private readonly DictionaryService $dictionaries,
    ) {}

    public function index(Request $request): array
    {
        $type = $request->query('type');

        return [
            'data' => $this->dictionaries->list(
                (int) $request->user()->tenant_id,
                is_string($type) ? $type : null,
                ! $request->boolean('all'),
            ),
        ];
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'type' => ['required', 'string', 'max:50'],
            'code' => ['required', 'string', 'max:50'],
            'label' => ['required', 'string', 'max:255'],
            'sort' => ['nullable', 'integer', 'min:0'],
            'is_active' => ['nullable', 'boolean'],
            'meta' => ['nullable', 'array'],
        ]);

        $dict = $this->dictionaries->upsert(
            (int) $request->user()->tenant_id,
            $validated['type'],
            $validated['code'],
            $validated['label'],
            (int) $request->user()->id,
            $validated['sort'] ?? null,
            $validated['is_active'] ?? true,
            $validated['meta'] ?? null,
        );

        return response()->json(['data' => $dict], 201);
    }

    public function deactivate(Request $request, Dictionary $dictionary): JsonResponse
    {
        abort_unless((int) $dictionary->tenant_id === (int) $request->user()->tenant_id, 403);

        $dict = $this->dictionaries->deactivate(
            (int) $request->user()->tenant_id,
            (int) $dictionary->id,
            (int) $request->user()->id,
        );

        return response()->json(['data' => $dict]);
    }
}
