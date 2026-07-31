#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * AUTOMETRIA ERP Engine Core
 * License issuer — runs ONLY on the closed licensing server.
 *
 * Usage:
 *   php tools/licensing/issue-license.php \
 *     --private=/secure/vault/private.pem \
 *     --domains=client.example.com,www.client.example.com \
 *     --expires=2027-12-31 \
 *     --hardware-hash=SHA256_HEX \
 *     --out=/tmp/autometria.lic
 */

$opts = getopt('', [
    'private:',
    'domains:',
    'expires:',
    'hardware-hash:',
    'out:',
    'payload-extra::',
]);

foreach (['private', 'domains', 'expires', 'hardware-hash', 'out'] as $required) {
    if (! isset($opts[$required]) || $opts[$required] === false || $opts[$required] === '') {
        fwrite(STDERR, "Missing --{$required}\n");
        exit(1);
    }
}

$privatePath = (string) $opts['private'];
if (! is_file($privatePath)) {
    fwrite(STDERR, "Private key not found: {$privatePath}\n");
    exit(1);
}

$privateKey = openssl_pkey_get_private((string) file_get_contents($privatePath));
if ($privateKey === false) {
    fwrite(STDERR, "Unable to load private key.\n");
    exit(1);
}

$expiresAt = strtotime((string) $opts['expires']);
if ($expiresAt === false) {
    fwrite(STDERR, "Invalid --expires value.\n");
    exit(1);
}

$domains = array_values(array_filter(array_map('trim', explode(',', (string) $opts['domains']))));
$hardwareHash = trim((string) $opts['hardware-hash']);

$payloadObject = [
    'product' => 'AUTOMETRIA_ERP',
    'issued_at' => time(),
    'expires_at' => $expiresAt,
    'allowed_domains' => $domains,
    'hardware_hash' => $hardwareHash,
    'copyright' => 'Себиев Ахмед Сулейманович',
];

if (isset($opts['payload-extra']) && is_string($opts['payload-extra']) && $opts['payload-extra'] !== '') {
    $extra = json_decode($opts['payload-extra'], true);
    if (! is_array($extra)) {
        fwrite(STDERR, "--payload-extra must be JSON object.\n");
        exit(1);
    }
    $payloadObject = array_merge($payloadObject, $extra);
}

$payloadJson = json_encode($payloadObject, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
$payloadB64 = base64_encode($payloadJson);

$signature = '';
$ok = openssl_sign($payloadB64, $signature, $privateKey, OPENSSL_ALGO_SHA256);
if (! $ok) {
    fwrite(STDERR, "openssl_sign failed.\n");
    exit(1);
}

$license = [
    'payload' => $payloadB64,
    'signature' => base64_encode($signature),
    'expires_at' => $expiresAt,
    'allowed_domains' => $domains,
    'hardware_hash' => $hardwareHash,
];

$encoded = base64_encode(json_encode($license, JSON_THROW_ON_ERROR));
$out = (string) $opts['out'];
$dir = dirname($out);
if (! is_dir($dir) && ! mkdir($dir, 0700, true) && ! is_dir($dir)) {
    fwrite(STDERR, "Cannot create output directory: {$dir}\n");
    exit(1);
}

file_put_contents($out, $encoded);
chmod($out, 0600);

fwrite(STDOUT, "Wrote license: {$out}\n");
fwrite(STDOUT, "Expires: ".gmdate('c', $expiresAt)."\n");
fwrite(STDOUT, "Domains: ".implode(', ', $domains)."\n");
