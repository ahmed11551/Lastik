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

use Autometria\Enums\OrderStatusEnum;
use Autometria\Exceptions\Domain\InvalidStatusTransitionException;
use Autometria\Models\Order;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class BulkOrderController extends Controller
{
    public function bulkStatus(Request $request): JsonResponse
    {
        $validator = Validator::make($request->all(), [
            'ids' => 'required|array|min:1|max:500',
            'ids.*' => 'integer|exists:orders,id',
            'status' => ['required', function ($attribute, $value, $fail) {
                $resolved = OrderStatusEnum::fromCrm($value) ?? OrderStatusEnum::tryFrom($value);
                if ($resolved === null) {
                    $fail("Статус '{$value}' не поддерживается");
                }
            }],
        ], [
            'ids.required' => 'Не выбрана ни одна запись',
            'ids.min' => 'Не выбрана ни одна запись',
            'ids.*.uuid' => 'Неверный формат ID заказа',
            'status.required' => 'Не указан статус',
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
        $resolved = OrderStatusEnum::fromCrm($validated['status']) ?? OrderStatusEnum::tryFrom($validated['status']);

        $count = DB::transaction(function () use ($validated, $resolved, $request): int {
            $query = Order::whereIn('id', $validated['ids'])
                ->where('tenant_id', $request->user()->tenant_id);

            $affected = $query->count();

            foreach ($query->get() as $order) {
                if (!$this->canTransition($order->status, $resolved)) {
                    throw new InvalidStatusTransitionException(
                        $order->status,
                        $resolved->value,
                        "Order #{$order->number}",
                    );
                }

                $order->update(['status' => $resolved->value]);
            }

            return $affected;
        });

        return response()->json([
            'success' => true,
            'updated_count' => $count,
            'message' => "Успешно обновлено {$count} записей",
        ]);
    }

    private function canTransition(string $from, OrderStatusEnum $to): bool
    {
        if ($to === OrderStatusEnum::CANCELLED) {
            return true;
        }

        $terminal = [
            OrderStatusEnum::COMPLETED->value,
            OrderStatusEnum::CANCELLED->value,
        ];

        return !in_array($from, $terminal, true);
    }
}
