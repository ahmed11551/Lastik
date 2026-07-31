<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright  (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author     Себиев Ахмед Сулейманович
 * @license    Proprietary & Confidential.
 */

declare(strict_types=1);

use Autometria\Exceptions\LicenseViolationException;
use Autometria\Http\Middleware\EnforceAutometriaLicense;
use Autometria\Services\Licensing\HardwareFingerprint;
use Illuminate\Http\Request;

beforeEach(function (): void {
    // Middleware bypasses local/testing — force production for these cases.
    $this->originalEnv = app()->environment();
    app()['env'] = 'production';

    $this->licenseDir = storage_path('framework/licensing');
    if (! is_dir($this->licenseDir)) {
        mkdir($this->licenseDir, 0755, true);
    }

    $this->licenseFile = $this->licenseDir.'/autometria.lic';
    $this->publicKeyFile = $this->licenseDir.'/public.pem';
    $this->privateKeyFile = $this->licenseDir.'/private.pem';

    $this->publicKeyBackup = is_file($this->publicKeyFile)
        ? (string) file_get_contents($this->publicKeyFile)
        : null;
    $this->privateKeyBackup = is_file($this->privateKeyFile)
        ? (string) file_get_contents($this->privateKeyFile)
        : null;
    $this->licenseBackup = is_file($this->licenseFile)
        ? (string) file_get_contents($this->licenseFile)
        : null;

    $res = openssl_pkey_new([
        'private_key_bits' => 2048,
        'private_key_type' => OPENSSL_KEYTYPE_RSA,
    ]);
    expect($res)->not->toBeFalse();

    openssl_pkey_export($res, $privateKey);
    $details = openssl_pkey_get_details($res);
    expect($details)->toBeArray()->and($details['key'] ?? null)->not->toBeEmpty();

    file_put_contents($this->privateKeyFile, $privateKey);
    file_put_contents($this->publicKeyFile, $details['key']);
    $this->testPrivateKey = $privateKey;
});

afterEach(function (): void {
    app()['env'] = $this->originalEnv ?? 'testing';

    if ($this->publicKeyBackup !== null) {
        file_put_contents($this->publicKeyFile, $this->publicKeyBackup);
    } else {
        @unlink($this->publicKeyFile);
    }

    if ($this->privateKeyBackup !== null) {
        file_put_contents($this->privateKeyFile, $this->privateKeyBackup);
    } else {
        @unlink($this->privateKeyFile);
    }

    if ($this->licenseBackup !== null) {
        file_put_contents($this->licenseFile, $this->licenseBackup);
    } else {
        @unlink($this->licenseFile);
    }
});

/**
 * @param  array<string, mixed>  $overridePayload
 */
function createMockLicense(array $overridePayload = [], ?string $privateKey = null): string
{
    $hardwareHash = HardwareFingerprint::generate();
    $payloadData = array_merge([
        'hardware_hash' => $hardwareHash,
        'allowed_domains' => ['localhost', '127.0.0.1', 'autometria.local'],
        'expires_at' => time() + 86400,
        'owner' => 'Себиев Ахмед Сулейманович',
    ], $overridePayload);

    $payload = json_encode($payloadData, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

    $signature = '';
    $ok = openssl_sign($payload, $signature, (string) $privateKey, OPENSSL_ALGO_SHA256);
    expect($ok)->toBeTrue();

    return base64_encode(json_encode([
        'payload' => $payload,
        'signature' => base64_encode($signature),
        'hardware_hash' => $payloadData['hardware_hash'],
        'allowed_domains' => $payloadData['allowed_domains'],
        'expires_at' => $payloadData['expires_at'],
    ], JSON_THROW_ON_ERROR));
}

test('fails when autometria.lic file is missing', function (): void {
    if (is_file($this->licenseFile)) {
        unlink($this->licenseFile);
    }

    $middleware = new EnforceAutometriaLicense;
    $request = Request::create('http://localhost/api/v1/ping');

    $middleware->handle($request, fn () => response('OK'));
})->throws(LicenseViolationException::class, 'LICENSE_MISSING');

test('fails when hardware fingerprint mismatch is detected', function (): void {
    $fakeHardwareHash = hash('sha256', 'SEBIEV_AHMED_AUTOMETRIA_FAKE_HARDWARE_ID');
    $invalidLicense = createMockLicense(['hardware_hash' => $fakeHardwareHash], $this->testPrivateKey);
    file_put_contents($this->licenseFile, $invalidLicense);

    $middleware = new EnforceAutometriaLicense;
    $request = Request::create('http://localhost/api/v1/ping');

    $middleware->handle($request, fn () => response('OK'));
})->throws(LicenseViolationException::class, 'HARDWARE_MISMATCH');

test('fails when request domain is not allowed', function (): void {
    $validLicense = createMockLicense(['allowed_domains' => ['authorized-domain.com']], $this->testPrivateKey);
    file_put_contents($this->licenseFile, $validLicense);

    $middleware = new EnforceAutometriaLicense;
    $request = Request::create('http://unauthorized-hacker-domain.com/api/v1/ping');

    $middleware->handle($request, fn () => response('OK'));
})->throws(LicenseViolationException::class, 'DOMAIN_MISMATCH');

test('fails when license is expired', function (): void {
    $expiredLicense = createMockLicense(['expires_at' => time() - 3600], $this->testPrivateKey);
    file_put_contents($this->licenseFile, $expiredLicense);

    $middleware = new EnforceAutometriaLicense;
    $request = Request::create('http://localhost/api/v1/ping');

    $middleware->handle($request, fn () => response('OK'));
})->throws(LicenseViolationException::class, 'LICENSE_EXPIRED');

test('passes successfully with valid license and valid hardware fingerprint', function (): void {
    $validLicense = createMockLicense([], $this->testPrivateKey);
    file_put_contents($this->licenseFile, $validLicense);

    $middleware = new EnforceAutometriaLicense;
    $request = Request::create('http://localhost/api/v1/ping');

    $response = $middleware->handle($request, fn () => response('OK'));

    expect($response->getContent())->toBe('OK');
});
