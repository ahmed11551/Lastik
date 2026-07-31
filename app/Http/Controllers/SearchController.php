<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\SearchService;
use Illuminate\Http\Request;

class SearchController extends Controller
{
    public function __construct(
        private readonly SearchService $search,
    ) {}

    public function __invoke(Request $request): array
    {
        $validated = $request->validate([
            'q' => ['required', 'string', 'min:2', 'max:100'],
        ]);

        $locationId = location_id();
        $permissions = $request->user()?->role?->permissions ?? [];
        if (in_array('locations.all', $permissions, true) || in_array('admin.dashboard', $permissions, true)) {
            $locationId = null;
        }

        return [
            'data' => $this->search->search(
                (int) $request->user()->tenant_id,
                $validated['q'],
                $locationId,
            ),
        ];
    }
}
