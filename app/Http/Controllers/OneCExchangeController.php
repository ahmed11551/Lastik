<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * CommerceML 2.10 exchange endpoint (1С: mode=checkauth|init|file|import).
 *
 * @package    Autometria\Http\Controllers
 * @copyright  (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Http\Controllers;

use Autometria\Models\ImportJob;
use Autometria\Services\Import\CommerceMLImportService;
use Autometria\Services\OneC\OneCSyncSettingsService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class OneCExchangeController extends Controller
{
    public function __construct(
        private readonly OneCSyncSettingsService $settings,
        private readonly CommerceMLImportService $imports,
    ) {}

    public function __invoke(Request $request): Response
    {
        $mode = (string) $request->query('mode', '');
        $type = (string) $request->query('type', 'catalog');

        if ($mode === 'checkauth') {
            return $this->checkAuth($request);
        }

        $tenantId = $this->authenticate($request);
        if ($tenantId === null) {
            return $this->plain('failure'."\n".'unauthorized', 401);
        }

        return match ($mode) {
            'init' => $this->plain("zip=no\nfile_limit=". (50 * 1024 * 1024)),
            'file' => $this->receiveFile($request, $tenantId, $type),
            'import' => $this->runImport($request, $tenantId),
            default => $this->plain('failure'."\n".'unknown mode'),
        };
    }

    private function checkAuth(Request $request): Response
    {
        $tenantId = $this->authenticate($request);
        if ($tenantId === null) {
            return $this->plain('failure'."\n".'unauthorized', 401);
        }

        $cookie = 'onec_'.Str::random(24);
        Cache::put('1c_session:'.$cookie, $tenantId, now()->addHours(2));

        return $this->plain("success\n{$cookie}\n{$cookie}");
    }

    private function receiveFile(Request $request, int $tenantId, string $type): Response
    {
        $filename = basename((string) $request->query('filename', 'import.xml'));
        if (! str_ends_with(strtolower($filename), '.xml')) {
            return $this->plain('failure'."\n".'only .xml accepted');
        }

        $body = $request->getContent();
        if ($body === '' || $body === false) {
            return $this->plain('failure'."\n".'empty body');
        }

        $relative = '1c/exchange/'.$tenantId.'/'.$filename;
        Storage::put($relative, $body);
        Cache::put('1c_file:'.$tenantId.':'.$filename, $relative, now()->addHours(2));

        ImportJob::query()->withoutGlobalScopes()->forceCreate([
            'tenant_id' => $tenantId,
            'source' => 'commerceml2',
            'file_name' => $filename,
            'channel' => 'auto_1c',
            'status' => 'pending',
            'summary' => [
                'file_type' => str_contains(strtolower($filename), 'offer') ? 'offers' : 'import',
                'exchange_type' => $type,
            ],
            'errors' => [],
        ]);

        return $this->plain('success');
    }

    private function runImport(Request $request, int $tenantId): Response
    {
        $filename = basename((string) $request->query('filename', 'import.xml'));
        $relative = Cache::get('1c_file:'.$tenantId.':'.$filename)
            ?? ('1c/exchange/'.$tenantId.'/'.$filename);

        if (! Storage::exists($relative)) {
            return $this->plain('failure'."\n".'file not found');
        }

        $absolute = Storage::path($relative);
        $fileType = str_contains(strtolower($filename), 'offer') ? 'offers' : 'import';

        try {
            $this->imports->import($absolute, $tenantId, null, [
                'file_name' => $filename,
                'channel' => 'auto_1c',
                'file_type' => $fileType,
            ]);
        } catch (Throwable $e) {
            return $this->plain('failure'."\n".$e->getMessage());
        }

        return $this->plain('success');
    }

    private function authenticate(Request $request): ?int
    {
        $user = (string) $request->getUser();
        $pass = (string) $request->getPassword();

        if ($user === '' || $pass === '') {
            // Cookie session from checkauth
            $cookie = (string) ($request->cookie('onec_sess')
                ?? $request->header('X-1C-Session')
                ?? '');
            if ($cookie !== '' && Cache::has('1c_session:'.$cookie)) {
                return (int) Cache::get('1c_session:'.$cookie);
            }

            return null;
        }

        $tenantId = $this->settings->findTenantIdByLogin($user);
        if ($tenantId === null) {
            return null;
        }

        return $this->settings->verifyBasicAuth($tenantId, $user, $pass) ? $tenantId : null;
    }

    private function plain(string $body, int $status = 200): Response
    {
        return response($body, $status)->header('Content-Type', 'text/plain; charset=UTF-8');
    }
}
