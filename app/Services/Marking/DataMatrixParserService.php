<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Services\Marking;

use Autometria\Exceptions\Domain\InvalidMarkingCodeException;

/**
 * GS1 DataMatrix parser (AI 01 GTIN, AI 21 Serial, AI 91/92 crypto).
 */
class DataMatrixParserService
{
    public const GS = "\u{001D}";

    /**
     * @return array{gtin: string, serial: string, crypto_tail: string|null, raw: string}
     *
     * @throws InvalidMarkingCodeException
     */
    public function parse(string $raw): array
    {
        $code = $this->normalize($raw);
        if ($code === '') {
            throw new InvalidMarkingCodeException('Пустой код маркировки DataMatrix');
        }

        // Prefer FNC1/GS-delimited segments; also support concatenated CIS without GS.
        $gtin = null;
        $serial = null;
        $crypto = null;

        if (str_contains($code, self::GS)) {
            $parts = explode(self::GS, $code);
            foreach ($parts as $part) {
                $part = trim($part);
                if ($part === '') {
                    continue;
                }
                if (str_starts_with($part, '01') && strlen($part) >= 16) {
                    $gtin = substr($part, 2, 14);
                } elseif (str_starts_with($part, '21')) {
                    $serial = substr($part, 2);
                } elseif (str_starts_with($part, '91') || str_starts_with($part, '92')) {
                    $crypto = ($crypto ?? '').$part;
                } elseif (preg_match('/^01(\d{14})21(.+)$/', $part, $m)) {
                    $gtin = $m[1];
                    $serial = $m[2];
                }
            }
        }

        if ($gtin === null || $serial === null) {
            // Concatenated: 01{GTIN14}21{SERIAL}[91…][92…]
            if (! preg_match('/^01(\d{14})21(.+)$/u', $code, $m)) {
                throw new InvalidMarkingCodeException('Некорректный формат GS1 DataMatrix (ожидаются AI 01 и AI 21)');
            }
            $gtin = $m[1];
            $rest = $m[2];

            // Split serial at crypto AIs 91/92 if present.
            if (preg_match('/^(.*?)(91.+|92.+)$/u', $rest, $cm)) {
                $serial = $cm[1];
                $crypto = $cm[2];
            } else {
                $serial = $rest;
            }
        }

        $gtin = (string) $gtin;
        $serial = (string) $serial;

        if (! preg_match('/^\d{14}$/', $gtin)) {
            throw new InvalidMarkingCodeException('GTIN (AI 01) должен содержать 14 цифр');
        }
        if ($serial === '' || strlen($serial) > 64) {
            throw new InvalidMarkingCodeException('Serial (AI 21) отсутствует или слишком длинный');
        }

        return [
            'gtin' => $gtin,
            'serial' => $serial,
            'crypto_tail' => $crypto,
            'raw' => $code,
        ];
    }

    public function looksLikeDataMatrix(string $raw): bool
    {
        $code = $this->normalize($raw);
        if ($code === '') {
            return false;
        }

        try {
            $this->parse($code);

            return true;
        } catch (InvalidMarkingCodeException) {
            return str_starts_with($code, '01') && strlen($code) >= 20;
        }
    }

    private function normalize(string $raw): string
    {
        $code = trim($raw);
        // Strip BOM / leading FNC1 if present as "]"
        if (str_starts_with($code, ']d2') || str_starts_with($code, ']C1')) {
            $code = substr($code, 3);
        }

        return $code;
    }
}
