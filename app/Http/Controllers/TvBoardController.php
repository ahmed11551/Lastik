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
        $locationId = $request->integer('location_id') ?: location_id();

        return [
            'data' => $this->tv->board(
                (int) ($request->user()?->tenant_id ?? tenant_id()),
                $locationId ? (int) $locationId : null,
            ),
        ];
    }
}
