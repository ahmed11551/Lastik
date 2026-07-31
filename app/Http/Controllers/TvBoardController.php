<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Services\TvBoardService;
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
