<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @package    Autometria\Services\OneC
 * @copyright  (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Services\OneC;

use Autometria\Models\Setting;
use Illuminate\Support\Str;

/**
 * Tenant-scoped 1C CommerceML credentials + sync options (settings group=1c).
 */
final class OneCSyncSettingsService
{
    public const GROUP = '1c';

    public const KEY = 'sync';

    /**
     * @return array{
     *   login: string,
     *   password_set: bool,
     *   password_hint: string|null,
     *   exchange_url: string,
     *   options: array{update_stocks: bool, update_prices: bool, create_products: bool, sync_mode: string, remote_url: string|null}
     * }
     */
    public function getPublicCredentials(int $tenantId): array
    {
        $cfg = $this->load($tenantId);

        return [
            'login' => (string) ($cfg['login'] ?? '1c_exchange'),
            'password_set' => ! empty($cfg['password_hash']),
            'password_hint' => isset($cfg['password_hint']) ? (string) $cfg['password_hint'] : null,
            'exchange_url' => $this->exchangeUrl(),
            'export_orders_url' => $this->exportOrdersUrl(),
            'export_offers_url' => $this->exportOffersUrl(),
            'json_push_url' => $this->jsonPushUrl(),
            'options' => [
                'update_stocks' => (bool) ($cfg['options']['update_stocks'] ?? true),
                'update_prices' => (bool) ($cfg['options']['update_prices'] ?? true),
                'create_products' => (bool) ($cfg['options']['create_products'] ?? true),
                'sync_mode' => (string) ($cfg['options']['sync_mode'] ?? 'manual'),
                'remote_url' => isset($cfg['options']['remote_url']) ? (string) $cfg['options']['remote_url'] : null,
            ],
        ];
    }

    /**
     * @return array{login: string, password: string, password_hint: string, exchange_url: string}
     */
    public function resetCredentials(int $tenantId): array
    {
        $login = '1c_'.Str::lower(Str::random(6));
        $password = Str::password(20, symbols: false);
        $hint = substr($password, 0, 3).str_repeat('•', max(0, strlen($password) - 6)).substr($password, -3);

        $cfg = $this->load($tenantId);
        $cfg['login'] = $login;
        $cfg['password_hash'] = password_hash($password, PASSWORD_BCRYPT);
        $cfg['password_hint'] = $hint;
        $cfg['options'] = $cfg['options'] ?? [
            'update_stocks' => true,
            'update_prices' => true,
            'create_products' => true,
        ];
        $this->save($tenantId, $cfg);

        return [
            'login' => $login,
            'password' => $password,
            'password_hint' => $hint,
            'exchange_url' => $this->exchangeUrl(),
        ];
    }

    /**
     * @param  array{update_stocks?: bool, update_prices?: bool, create_products?: bool, sync_mode?: string, remote_url?: string|null}  $options
     * @return array{update_stocks: bool, update_prices: bool, create_products: bool, sync_mode: string, remote_url: string|null}
     */
    public function updateOptions(int $tenantId, array $options): array
    {
        $cfg = $this->load($tenantId);
        $mode = (string) ($options['sync_mode'] ?? $cfg['options']['sync_mode'] ?? 'manual');
        if (! in_array($mode, ['manual', 'auto'], true)) {
            $mode = 'manual';
        }
        $cfg['options'] = [
            'update_stocks' => (bool) ($options['update_stocks'] ?? $cfg['options']['update_stocks'] ?? true),
            'update_prices' => (bool) ($options['update_prices'] ?? $cfg['options']['update_prices'] ?? true),
            'create_products' => (bool) ($options['create_products'] ?? $cfg['options']['create_products'] ?? true),
            'sync_mode' => $mode,
            'remote_url' => array_key_exists('remote_url', $options)
                ? ($options['remote_url'] !== null && $options['remote_url'] !== '' ? (string) $options['remote_url'] : null)
                : ($cfg['options']['remote_url'] ?? null),
        ];
        if (empty($cfg['login'])) {
            $cfg['login'] = '1c_exchange';
        }
        $this->save($tenantId, $cfg);

        return $cfg['options'];
    }

    public function verifyBasicAuth(int $tenantId, string $login, string $password): bool
    {
        $cfg = $this->load($tenantId);
        if (($cfg['login'] ?? '') !== $login) {
            return false;
        }
        $hash = (string) ($cfg['password_hash'] ?? '');
        if ($hash === '') {
            return false;
        }

        return password_verify($password, $hash);
    }

    /**
     * Resolve tenant by Basic Auth login (for exchange endpoint without sanctum).
     */
    public function findTenantIdByLogin(string $login): ?int
    {
        $row = Setting::query()->withoutGlobalScopes()
            ->where('group', self::GROUP)
            ->where('key', self::KEY)
            ->get()
            ->first(function (Setting $s) use ($login): bool {
                $v = $s->value ?? [];

                return ($v['login'] ?? null) === $login;
            });

        return $row?->tenant_id !== null ? (int) $row->tenant_id : null;
    }

    public function exchangeUrl(): string
    {
        return $this->apiBase().'/1c/exchange';
    }

    public function exportOrdersUrl(): string
    {
        return $this->apiBase().'/1c/export/orders';
    }

    public function exportOffersUrl(): string
    {
        return $this->apiBase().'/1c/export/offers';
    }

    public function jsonPushUrl(): string
    {
        return $this->apiBase().'/1c/json/push';
    }

    private function apiBase(): string
    {
        return rtrim((string) config('app.url', 'https://your-domain.com'), '/').'/api/v1';
    }

    /**
     * @return array<string, mixed>
     */
    private function load(int $tenantId): array
    {
        $row = Setting::query()->withoutGlobalScopes()
            ->where('tenant_id', $tenantId)
            ->where('group', self::GROUP)
            ->where('key', self::KEY)
            ->first();

        $value = is_array($row?->value) ? $row->value : [];

        return array_merge([
            'login' => '1c_exchange',
            'password_hash' => null,
            'password_hint' => null,
            'options' => [
                'update_stocks' => true,
                'update_prices' => true,
                'create_products' => true,
                'sync_mode' => 'manual',
                'remote_url' => null,
            ],
        ], $value);
    }

    /**
     * @param  array<string, mixed>  $cfg
     */
    private function save(int $tenantId, array $cfg): void
    {
        Setting::query()->withoutGlobalScopes()->updateOrCreate(
            [
                'tenant_id' => $tenantId,
                'group' => self::GROUP,
                'key' => self::KEY,
                'scope' => 'global',
            ],
            [
                'value' => $cfg,
            ],
        );
    }
}
