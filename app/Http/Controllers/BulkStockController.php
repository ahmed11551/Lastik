<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @package    Autometria\Core
 * @copyright  (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author     Себиев Ахмед Сулейманович
 * @license    Proprietary & Confidential.
 */

declare(strict_types=1);

namespace Autometria\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class BulkStockController extends Controller
{
    public function bulkUpdate(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'ids' => 'required|array|min:1|max:500',
            'ids.*' => 'integer|exists:stocks,id',
            'action' => 'required|in:update_category,adjust_actual,adjust_reserved',
            'payload' => 'required|array',
            'payload.category' => 'nullable|string',
            'payload.adjustment' => 'nullable|integer',
        ], [
            'ids.required' => 'Не выбрана ни одна запись',
            'ids.min' => 'Не выбрана ни одна запись',
            'ids.*.exists' => 'Выбранный товар не найден',
            'action.required' => 'Не указано действие',
            'action.in' => 'Недопустимое действие',
            'payload.required' => 'Не указаны параметры операции',
        ]);

        if ($validator->fails()) {
            throw new ValidationException(
                $validator,
                response()->json([
                    'message' => 'Ошибка валидации',
                    'errors' => $validator->errors(),
                ], 422)
            );
        }

        $validated = $validator->validated();

        $count = DB::transaction(function () use ($validated, $request): int {
            $query = DB::table('stocks')
                ->whereIn('id', $validated['ids'])
                ->where('tenant_id', $request->user()->tenant_id);

            $affected = $query->count();

            $adj = (int) ($validated['payload']['adjustment'] ?? 0);

            switch ($validated['action']) {
                case 'update_category':
                    DB::table('products_services')
                        ->join('stocks', 'products_services.id', '=', 'stocks.product_id')
                        ->whereIn('stocks.id', $validated['ids'])
                        ->where('stocks.tenant_id', $request->user()->tenant_id)
                        ->update(['products_services.category' => $validated['payload']['category']]);
                    break;

                case 'adjust_actual':
                    $query->update([
                        'actual' => DB::raw("COALESCE(actual, 0) + {$adj}"),
                    ]);
                    break;

                case 'adjust_reserved':
                    $query->update([
                        'reserved' => DB::raw("COALESCE(reserved, 0) + {$adj}"),
                    ]);
                    break;
            }

            return $affected;
        });

        return response()->json([
            'success' => true,
            'updated_count' => $count,
            'message' => "Успешно обновлено {$count} записей",
            'action' => $validated['action'],
        ]);
    }
}
