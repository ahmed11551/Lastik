<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Services\Marking;

use Autometria\Enums\MarkingValidationStatusEnum;
use Autometria\Exceptions\Domain\InvalidMarkingCodeException;

/**
 * ИС «Честный Знак» / ГИС МТ client (mock + future live API).
 */
class ChestnyZnakClient
{
    public function __construct(
        private readonly bool $mockMode = true,
    ) {}

    public static function fromConfig(): self
    {
        $mock = filter_var(env('MARKING_MOCK_MODE', true), FILTER_VALIDATE_BOOLEAN);

        return new self($mock);
    }

    /**
     * @param  array{gtin: string, serial: string, crypto_tail?: string|null, raw: string}  $parsed
     * @return array{status: MarkingValidationStatusEnum, payload: array<string, mixed>}
     *
     * @throws InvalidMarkingCodeException
     */
    public function validate(string $markingCode, array $parsed): array
    {
        if ($this->mockMode) {
            return $this->validateMock($markingCode, $parsed);
        }

        // Live GIS MT not wired in Block 3.3 — fail closed.
        throw new InvalidMarkingCodeException(
            'Живой контур Честного Знака не сконфигурирован (MARKING_MOCK_MODE=false)',
        );
    }

    /**
     * @param  array{gtin: string, serial: string, crypto_tail?: string|null, raw: string}  $parsed
     * @return array{status: MarkingValidationStatusEnum, payload: array<string, mixed>}
     *
     * @throws InvalidMarkingCodeException
     */
    private function validateMock(string $markingCode, array $parsed): array
    {
        $upper = strtoupper($markingCode);

        if (str_contains($upper, 'EXPIRED')) {
            throw new InvalidMarkingCodeException(
                'Марка просрочена (Честный Знак: EXPIRED)',
            );
        }

        if (str_contains($upper, 'SOLD') || str_contains($upper, 'REUSED')) {
            throw new InvalidMarkingCodeException(
                'Марка уже использована (Честный Знак: SOLD)',
            );
        }

        // Valid test CIS typically start with 01046… (RU GTIN prefix 046).
        if (str_starts_with($markingCode, '01046') || str_starts_with($parsed['gtin'], '046')) {
            return [
                'status' => MarkingValidationStatusEnum::VALID,
                'payload' => [
                    'source' => 'mock',
                    'reason' => 'VALID',
                    'gtin' => $parsed['gtin'],
                    'serial' => $parsed['serial'],
                    'crypto_tail' => $parsed['crypto_tail'] ?? null,
                ],
            ];
        }

        throw new InvalidMarkingCodeException(
            'Марка отклонена Честным Знаком (контрафакт / INVALID)',
        );
    }
}
