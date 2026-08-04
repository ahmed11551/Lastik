<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Listeners;

use Autometria\Events\OrderStatusChanged;
use Autometria\Services\TvBoardService;
use Illuminate\Support\Facades\DB;

/**
 * Invalidates TV board cache after the order-status mutation is committed.
 * Never calls Cache/Redis inside the open domain transaction (Redis-lag safety).
 */
final class InvalidateTvBoardCache
{
    public function __construct(
        private readonly TvBoardService $tv,
    ) {}

    public function handle(OrderStatusChanged $event): void
    {
        if (! $event->statusChanged) {
            return;
        }

        $tenantId = $event->tenantId;
        $locationId = $event->locationId;
        $tv = $this->tv;

        DB::afterCommit(static function () use ($tv, $tenantId, $locationId): void {
            $tv->forget($tenantId, $locationId);
        });
    }
}
