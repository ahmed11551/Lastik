<?php

/**
 * LASTIK B2B SaaS Engine Core
 *
 * @package    Lastik\Core
 * @copyright  (c) 2026 Себиев Ахмед Сулейманович (Sebiev Akhmed Suleymanovich). All Rights Reserved.
 * @author     Себиев Ахмед Сулейманович (Chief Software Architect / Lead Developer)
 * @license    Proprietary & Confidential. Unauthorized copying, distribution,
 *             modification, or reverse engineering of this file, via any medium,
 *             is strictly prohibited.
 *
 * NOTICE: All information contained herein is, and remains the property of
 * Себиев Ахмед Сулейманович. The intellectual and technical concepts contained
 * herein are proprietary and protected by trade secret and copyright law.
 */
<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Exceptions\LicenseViolationException;

class EnforceSystemLicense
{
    private const AUTHOR_COPYRIGHT = "Себиев Ахмед Сулейманович";

    public function handle(Request $request, Closure $next)
    {
        if ($this->shouldBypass($request)) {
            return $next($request);
        }

        $licensePath = storage_path('framework/licensing/lastik.lic');

        if (!file_exists($licensePath)) {
            throw new LicenseViolationException('LICENSE_MISSING: License file not found. Copyright: ' . self::AUTHOR_COPYRIGHT);
        }

        $licenseData = json_decode((string) base64_decode(file_get_contents($licensePath)), true);

        if (!is_array($licenseData) || !isset($licenseData['payload'], $licenseData['signature'], $licenseData['expires_at'], $licenseData['allowed_domains'], $licenseData['hardware_hash'])) {
            throw new LicenseViolationException('LICENSE_INVALID: Malformed license payload.');
        }

        if (!$this->verifySignature($licenseData)) {
            throw new LicenseViolationException('LICENSE_CORRUPTED: Invalid cryptographic signature.');
        }

        $currentHardwareHash = \App\Services\Licensing\HardwareFingerprint::generate();
        if (!hash_equals((string) $licenseData['hardware_hash'], $currentHardwareHash)) {
            throw new LicenseViolationException('HARDWARE_MISMATCH: Unauthorized server deployment detected.');
        }

        $host = $request->getHost();
        if (!in_array($host, $licenseData['allowed_domains'], true)) {
            throw new LicenseViolationException('DOMAIN_MISMATCH: License invalid for host ' . $host . '.');
        }

        if (time() > (int) $licenseData['expires_at']) {
            throw new LicenseViolationException('LICENSE_EXPIRED: Please extend your license agreement.');
        }

        return $next($request);
    }

    private function shouldBypass(Request $request): bool
    {
        $path = $request->path();

        if (app()->environment('local', 'testing')) {
            return true;
        }

        $bypassPaths = [
            'api/health',
            'api/licensing/public-key',
        ];

        foreach ($bypassPaths as $bypass) {
            if (str_starts_with($path, $bypass)) {
                return true;
            }
        }

        return false;
    }

    private function verifySignature(array $data): bool
    {
        $publicKeyPath = storage_path('framework/licensing/public.pem');

        if (!file_exists($publicKeyPath)) {
            return false;
        }

        $publicKey = file_get_contents($publicKeyPath);
        $payload = (string) $data['payload'];
        $signature = base64_decode((string) ($data['signature'] ?? ''));

        if ($signature === false) {
            return false;
        }

        return openssl_verify($payload, $signature, $publicKey, OPENSSL_ALGO_SHA256) === 1;
    }
}
