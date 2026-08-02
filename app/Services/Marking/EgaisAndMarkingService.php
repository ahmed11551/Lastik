<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Services\Marking;

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
            throw new InvalidMarkingCodeException(
                'Для маркированного товара требуется код DataMatrix (marking_code)',
            );
        }

        $parsed = $this->parser->parse($code);
        $result = $this->chestnyZnak->validate($code, $parsed);

        MarkingValidation::query()->withoutGlobalScopes()->forceCreate([
            'tenant_id' => $tenantId,
            'marking_code' => $code,
            'gtin' => $parsed['gtin'],
            'status' => $result['status']->value,
            'response_payload' => $result['payload'],
            'created_at' => now(),
        ]);

        return [
            'marking_code' => $code,
            'gtin' => $parsed['gtin'],
            'serial_number' => $parsed['serial'],
        ];
    }
}
