<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Services;

use Autometria\Models\Order;
use Autometria\Models\Vehicle;
use Illuminate\Support\Facades\Cache;

final class TvBoardService
{
    public const CACHE_TTL_SECONDS = 5;

    /**
     * TV-табло текущих работ (п. 42) с коротким cache/Redis TTL.
     *
     * @return array{location_id: int|null, columns: array<string, list<array<string, mixed>>>}
     */
    public function board(int $tenantId, ?int $locationId = null): array
    {
        $cacheKey = $this->cacheKey($tenantId, $locationId);

        /** @var array{location_id: int|null, columns: array<string, list<array<string, mixed>>>} $payload */
        $payload = Cache::remember(
            $cacheKey,
            self::CACHE_TTL_SECONDS,
            fn (): array => $this->buildBoard($tenantId, $locationId),
        );

        return $payload;
    }

    public function forget(int $tenantId, ?int $locationId = null): void
    {
        Cache::forget($this->cacheKey($tenantId, $locationId));
        if ($locationId !== null) {
            Cache::forget($this->cacheKey($tenantId, null));
        }
    }

    private function cacheKey(int $tenantId, ?int $locationId): string
    {
        return sprintf('tv_board:%d:%s', $tenantId, $locationId === null ? 'all' : (string) $locationId);
    }

    /**
     * @return array{location_id: int|null, columns: array<string, list<array<string, mixed>>>}
     */
    private function buildBoard(int $tenantId, ?int $locationId): array
    {
        $query = Order::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->whereIn('status', [
                Order::STATUS_CREATED,
                Order::STATUS_IN_PROGRESS,
                Order::STATUS_READY,
                Order::STATUS_ISSUED,
            ])
            ->where(function ($q): void {
                $q->where('scenario', 'with_installation')
                    ->orWhereNull('scenario')
                    ->orWhere('scenario', '!=', 'without_installation');
            })
            ->latest('id')
            ->limit(100);

        if ($locationId !== null) {
            $query->where('location_id', $locationId);
        }

        $orders = $query->get();
        $vehicleIds = $orders->pluck('vehicle_id')->filter()->unique()->all();
        $vehicles = Vehicle::query()->withoutGlobalScopes()
            ->whereIn('id', $vehicleIds)
            ->get()
            ->keyBy('id');

        $map = function (Order $o) use ($vehicles): array {
            $vehicle = $o->vehicle_id ? $vehicles->get($o->vehicle_id) : null;

            return [
                'id' => $o->id,
                'number' => $o->number,
                'status' => $o->status,
                'scenario' => $o->scenario,
                'plate' => $vehicle?->plate,
                'vehicle' => $vehicle ? trim(($vehicle->brand ?? '').' '.($vehicle->model ?? '')) : null,
            ];
        };

        return [
            'location_id' => $locationId,
            'columns' => [
                'queue' => $orders->where('status', Order::STATUS_CREATED)->values()->map($map)->all(),
                'in_progress' => $orders->where('status', Order::STATUS_IN_PROGRESS)->values()->map($map)->all(),
                'ready' => $orders->whereIn('status', [Order::STATUS_READY, Order::STATUS_ISSUED])->values()->map($map)->all(),
            ],
        ];
    }
}
