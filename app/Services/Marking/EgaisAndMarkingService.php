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
use Autometria\Models\MarkingValidation;
use Autometria\Models\ProductService;

/**
 * Orchestrator: parse DataMatrix → validate via Честный Знак → persist log.
 */
class EgaisAndMarkingService
{
    public function __construct(
        private readonly DataMatrixParserService $parser,
        private readonly ChestnyZnakClient $chestnyZnak,
    ) {}

    /**
     * Ensure marked product has a valid CIS; returns parsed fields for order_item.
     *
     * @return array{marking_code: ?string, gtin: ?string, serial_number: ?string}
     *
     * @throws InvalidMarkingCodeException
     */
    public function assertValidMarking(
        int $tenantId,
        ProductService $product,
        ?string $markingCode,
    ): array {
        if (! (bool) $product->is_marked) {
            return [
                'marking_code' => null,
                'gtin' => null,
                'serial_number' => null,
            ];
        }

        $code = trim((string) $markingCode);
        if ($code === '') {
            throw InvalidMarkingCodeException::required();
        }

        try {
            $parsed = $this->parser->parse($code);
            $result = $this->chestnyZnak->validate($code, $parsed);

            $this->logValidation(
                $tenantId,
                $code,
                $parsed['gtin'],
                $result['status'],
                $result['payload'],
            );

            return [
                'marking_code' => $code,
                'gtin' => $parsed['gtin'],
                'serial_number' => $parsed['serial'],
            ];
        } catch (InvalidMarkingCodeException $e) {
            $gtin = '00000000000000';
            try {
                $partial = $this->parser->parse($code);
                $gtin = $partial['gtin'];
            } catch (InvalidMarkingCodeException) {
                // keep placeholder GTIN for audit row
            }

            $status = str_contains(strtoupper($e->getMessage()), 'EXPIRED')
                ? MarkingValidationStatusEnum::EXPIRED
                : (str_contains(strtoupper($e->getMessage()), 'SOLD')
                    ? MarkingValidationStatusEnum::SOLD
                    : MarkingValidationStatusEnum::INVALID);

            $this->logValidation($tenantId, $code, $gtin, $status, [
                'source' => 'mock',
                'error' => $e->getMessage(),
                'error_code' => $e->errorCode,
            ]);

            throw $e;
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function logValidation(
        int $tenantId,
        string $markingCode,
        string $gtin,
        MarkingValidationStatusEnum $status,
        array $payload,
    ): void {
        MarkingValidation::query()->withoutGlobalScopes()->forceCreate([
            'tenant_id' => $tenantId,
            'marking_code' => $markingCode,
            'gtin' => $gtin,
            'status' => $status->value,
            'response_payload' => $payload,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
