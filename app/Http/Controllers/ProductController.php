<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 */

declare(strict_types=1);

namespace Autometria\Http\Controllers;

use Autometria\Models\ProductService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProductController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $q = trim((string) $request->query('q', ''));
        $likeOp = DB::getDriverName() === 'pgsql' ? 'ilike' : 'like';

        $query = ProductService::query()->orderBy('name');

        if ($q !== '') {
            $like = '%'.$q.'%';
            $query->where(function ($builder) use ($like, $likeOp): void {
                $builder
                    ->where('name', $likeOp, $like)
                    ->orWhere('article', $likeOp, $like)
                    ->orWhere('external_id', $likeOp, $like)
                    ->orWhere('brand', $likeOp, $like);
            });
        }

        return response()->json([
            'data' => $query->limit(200)->get(),
        ]);
    }
}
