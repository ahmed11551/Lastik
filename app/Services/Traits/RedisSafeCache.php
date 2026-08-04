<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Services\Traits;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Predis\Connection\ConnectionException;
use Throwable;

/**
 * Graceful Redis degradation (TD-3).
 *
 * Оборачивает вызовы Cache-фасада так, что при недоступности Redis
 * (ConnectionException / любой Throwable из клиента) выполняется прямой
 * fallback без генерации 500. Инвалидация (forget/forever) — best-effort:
 * падение Redis при ней не критично для целостности данных.
 */
trait RedisSafeCache
{
    /**
     * Прочитать/вычислить значение с fallback на прямой вызов при сбое Redis.
     *
     * @template T
     * @param callable():T $compute прямой источник данных (БД/сервис)
     * @return T
     */
    protected function safeRemember(string $key, int $ttlSeconds, callable $compute): mixed
    {
        try {
            $cached = Cache::get($key);
            if ($cached !== null) {
                return $cached;
            }
        } catch (ConnectionException | Throwable $e) {
            Log::warning('Redis unavailable: cache read failed, falling back to DB', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            return $compute();
        }

        $value = $compute();

        try {
            Cache::put($key, $value, $ttlSeconds);
        } catch (ConnectionException | Throwable $e) {
            Log::warning('Redis unavailable: cache write skipped', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
        }

        return $value;
    }

    /**
     * Инвалидация — best-effort. Сбой Redis не бросается наверх.
     */
    protected function safeForget(string $key): void
    {
        try {
            Cache::forget($key);
        } catch (ConnectionException | Throwable $e) {
            Log::warning('Redis unavailable: cache invalidation skipped', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * Атомарный счётчик версии (best-effort). Возвращает 1 при сбое Redis.
     */
    protected function safeVersionKey(string $key, int $default = 1): int
    {
        try {
            return (int) Cache::get($key, $default);
        } catch (ConnectionException | Throwable $e) {
            Log::warning('Redis unavailable: version read fell back to default', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);

            return $default;
        }
    }

    protected function safeIncrementVersion(string $key, int $current): void
    {
        try {
            Cache::forever($key, $current + 1);
        } catch (ConnectionException | Throwable $e) {
            Log::warning('Redis unavailable: version bump skipped', [
                'key' => $key,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
