<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @package    Autometria\Http\Controllers\Api\V1\OneC
 * @copyright  (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author     Себиев Ахмед Сулейманович (Chief Software Architect / Lead Developer)
 * @license    Proprietary & Confidential.
 */

declare(strict_types=1);

namespace Autometria\Http\Controllers\Api\V1\OneC;

use Autometria\Jobs\ProcessCommerceMLCatalogJob;
use Autometria\Jobs\ProcessCommerceMLOffersJob;
use Autometria\Services\OneC\OneCAuthService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Response;
use Illuminate\Support\Facades\Storage;

/**
 * Контроллер обмена с 1С по протоколу CommerceML 2.10.
 *
 * Роут: GET/POST /api/v1/1c/exchange
 * Параметр mode: checkauth | init | file | import
 */
final class OneCExchangeController
{
    private const COOKIE_NAME = '1c_session';

    public function __construct(
        private readonly OneCAuthService $auth,
    ) {}

    public function handle(Request $request): \Illuminate\Http\Response
    {
        $mode = (string) $request->query('mode', '');

        return match ($mode) {
            'checkauth' => $this->checkauth($request),
            'init' => $this->init(),
            'file' => $this->file($request),
            'import' => $this->import($request),
            default => $this->text("failure\nunknown mode: {$mode}", 400),
        };
    }

    /**
     * Режим checkauth: HTTP Basic Auth, выдача cookie сессии.
     * Формат ответа 1С: <cookie_name>\n<cookie_value>\n<token> (или success\n...).
     */
    private function checkauth(Request $request): \Illuminate\Http\Response
    {
        $user = (string) $request->server('PHP_AUTH_USER')
            ?: (string) $request->header('php-auth-user', '');
        $pass = (string) $request->server('PHP_AUTH_PW')
            ?: (string) $request->header('php-auth-pw', '');

        // 1С шлёт Authorization: Basic base64(user:pass).
        if ($user === '' || $pass === '') {
            $authHeader = null;
            if ($request->hasHeader('Authorization')) {
                $authHeader = $request->header('Authorization');
            } elseif ($request->server('HTTP_AUTHORIZATION') !== null) {
                $authHeader = $request->server('HTTP_AUTHORIZATION');
            }
            if ($authHeader !== null) {
                if (is_array($authHeader)) {
                    $authHeader = $authHeader[0] ?? '';
                }
                $authHeader = (string) $authHeader;
                if (str_starts_with($authHeader, 'Basic ')) {
                    $decoded = base64_decode(substr($authHeader, 6), true);
                    if ($decoded !== false && str_contains($decoded, ':')) {
                        [$user, $pass] = explode(':', $decoded, 2);
                    }
                }
            }
        }

        $token = $this->auth->authenticate($user, $pass);

        if ($token === null) {
            return $this->text('failure', 401);
        }

        $cookie = cookie(self::COOKIE_NAME, $token, 120, null, null, false, false);

        // 1С ожидает: имя cookie, значение cookie, доп. заголовок сессии.
        return $this->text("success\n" . self::COOKIE_NAME . "\n" . $token)->withCookie($cookie);
    }

    /**
     * Режим init: параметры обмена.
     */
    private function init(): \Illuminate\Http\Response
    {
        return $this->text("zip=no\nfile_limit=10485760");
    }

    /**
     * Режим file: сохранение загружаемого файла (import.xml / offers.xml).
     */
    private function file(Request $request): \Illuminate\Http\Response
    {
        $session = $request->cookie(self::COOKIE_NAME);
        if (! $this->auth->validateSession($session)) {
            return $this->text('failure', 403);
        }

        $filename = (string) $request->query('filename', '');
        if ($filename === '') {
            return $this->text('failure', 400);
        }

        // 1С шлёт тело файла (raw) в POST. Сохраняем во временное хранилище.
        $content = $request->getContent();
        if ($content === '') {
            return $this->text('failure', 400);
        }

        Storage::disk('local')->put("1c_imports/{$filename}", $content);

        return $this->text('success');
    }

    /**
     * Режим import: валидация файла и постановка джобов в очередь.
     */
    private function import(Request $request): \Illuminate\Http\Response
    {
        $session = $request->cookie(self::COOKIE_NAME);
        if (! $this->auth->validateSession($session)) {
            return $this->text('failure', 403);
        }

        $filename = (string) $request->query('filename', '');
        if ($filename === '' || ! Storage::disk('local')->exists("1c_imports/{$filename}")) {
            return $this->text('failure', 400);
        }

        $tenantId = (int) ($request->attributes->get('tenant_id') ?? $this->resolveTenantId());

        if (str_contains($filename, 'offer')) {
            ProcessCommerceMLOffersJob::dispatch($tenantId, $filename);
        } else {
            ProcessCommerceMLCatalogJob::dispatch($tenantId, $filename);
        }

        return $this->text('success');
    }

    private function resolveTenantId(): ?int
    {
        // В реальном окружении tenant берётся из контекста сервисного аккаунта.
        return null;
    }

    private function text(string $body, int $status = 200): \Illuminate\Http\Response
    {
        return Response::make($body, $status, [
            'Content-Type' => 'text/plain; charset=utf-8',
        ]);
    }
}
