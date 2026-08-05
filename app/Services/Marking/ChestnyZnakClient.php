<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Services\Marking;

use Autometria\DTOs\Marking\ChestnyZnakValidationResult;
use Autometria\Enums\MarkingValidationStatusEnum;
use Autometria\Exceptions\Domain\InvalidMarkingCodeException;
use Illuminate\Support\Facades\Http;

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

        // Production-ready live GIS MT validation (switched by MARKING_MOCK_MODE=false).
        return $this->liveValidate($markingCode, $parsed);
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
                'MARKING_EXPIRED',
            );
        }

        if (str_contains($upper, 'SOLD') || str_contains($upper, 'REUSED')) {
            throw new InvalidMarkingCodeException(
                'Марка уже использована (Честный Знак: SOLD)',
                'MARKING_SOLD',
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
            'MARKING_INVALID',
        );
    }

    /**
     * Живой контур ГИС МТ (Честный Знак) — проверка CIS через API.
     * Production-ready; переключается флагом MARKING_MOCK_MODE=false.
     *
     * @param  array{gtin: string, serial: string, crypto_tail?: string|null, raw: string}  $parsed
     * @return array{status: MarkingValidationStatusEnum, payload: array<string, mixed>}
     *
     * @throws InvalidMarkingCodeException
     */
    public function liveValidate(string $markingCode, array $parsed): array
    {
        $baseUrl = rtrim((string) config('services.marking.api_url', env('CHESTNY_ZNAK_API_URL', 'https://trueapi.ruba.ru/api/v3')), '/');
        $token = config('services.marking.token', env('CHESTNY_ZNAK_API_TOKEN'));

        if ($token === null || $token === '') {
            throw new InvalidMarkingCodeException(
                'Не задан CHESTNY_ZNAK_API_TOKEN для живого контура Честного Знака',
                'MARKING_LIVE_UNAVAILABLE',
            );
        }

        $response = Http::timeout((int) env('CHESTNY_ZNAK_TIMEOUT', 10))
            ->withToken($token)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->post("{$baseUrl}/products/cises/info", [
                'cises' => [$parsed['raw']],
            ]);

        if (! $response->successful()) {
            throw new InvalidMarkingCodeException(
                'Ошибка обращения к ГИС МТ: HTTP '.$response->status(),
                'MARKING_GIS_ERROR',
            );
        }

        $body = $response->json('cis.0', []);
        $status = match (strtoupper((string) ($body['status'] ?? ''))) {
            'UNUSED' => MarkingValidationStatusEnum::VALID,
            'USED' => MarkingValidationStatusEnum::SOLD,
            'EXITED' => MarkingValidationStatusEnum::EXPIRED,
            default => MarkingValidationStatusEnum::INVALID,
        };

        if ($status === MarkingValidationStatusEnum::INVALID) {
            throw new InvalidMarkingCodeException(
                'Марка отклонена ГИС МТ (контрафакт / INVALID)',
                'MARKING_INVALID',
            );
        }

        return [
            'status' => $status,
            'payload' => [
                'source' => 'gis_mt',
                'reason' => $status->value,
                'gtin' => $body['gtin'] ?? $parsed['gtin'],
                'serial' => $body['serial'] ?? $parsed['serial'],
                'cis_status' => $body['status'] ?? null,
            ],
        ];
    }

    /**
     * Живой контур ГИС МТ — раскрепление марки при возврате.
     *
     * @return array{status: MarkingValidationStatusEnum, payload: array<string, mixed>}
     */
    public function liveUnbind(string $markingCode, string $gtin = '00000000000000'): array
    {
        $baseUrl = rtrim((string) config('services.marking.api_url', env('CHESTNY_ZNAK_API_URL', 'https://trueapi.ruba.ru/api/v3')), '/');
        $token = config('services.marking.token', env('CHESTNY_ZNAK_API_TOKEN'));

        if ($token === null || $token === '') {
            throw new InvalidMarkingCodeException(
                'Не задан CHESTNY_ZNAK_API_TOKEN для живого контура Честного Знака',
                'MARKING_UNBIND_UNAVAILABLE',
            );
        }

        // Withdrawal (раскрепление) endpoint ГИС МТ.
        $response = Http::timeout((int) env('CHESTNY_ZNAK_TIMEOUT', 10))
            ->withToken($token)
            ->withHeaders(['Content-Type' => 'application/json'])
            ->post("{$baseUrl}/products/cises/unbind", [
                'cis' => $markingCode,
                'gtin' => $gtin,
            ]);

        if (! $response->successful()) {
            throw new InvalidMarkingCodeException(
                'Ошибка раскрепления в ГИС МТ: HTTP '.$response->status(),
                'MARKING_UNBIND_ERROR',
            );
        }

        return [
            'status' => MarkingValidationStatusEnum::UNBOUND,
            'payload' => [
                'source' => 'gis_mt',
                'reason' => 'UNBOUND',
                'gtin' => $gtin,
                'marking_code' => $markingCode,
            ],
        ];
    }

    /**
     * Раскрепление марки при возврате (mock / future GIS MT withdrawal).
     *
     * @return array{status: MarkingValidationStatusEnum, payload: array<string, mixed>}
     */
    public function unbind(string $markingCode, string $gtin = '00000000000000'): array
    {
        if ($this->mockMode) {
            return [
                'status' => MarkingValidationStatusEnum::UNBOUND,
                'payload' => [
                    'source' => 'mock',
                    'reason' => 'UNBOUND',
                    'gtin' => $gtin,
                    'marking_code' => $markingCode,
                ],
            ];
        }

        return $this->liveUnbind($markingCode, $gtin);
    }
}
