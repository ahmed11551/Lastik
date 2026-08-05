<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\DTOs\Marking;

use Autometria\Enums\MarkingValidationStatusEnum;

/**
 * Результат валидации кода маркировки в ГИС МТ (Честный Знак).
 * Используется и в mock-, и в live-режимах для единого контракта.
 */
final class ChestnyZnakValidationResult
{
    public function __construct(
        public readonly MarkingValidationStatusEnum $status,
        public readonly string $source, // 'mock' | 'gis_mt'
        public readonly ?string $code = null,
        public readonly ?string $message = null,
        public readonly array $payload = [],
    ) {}

    public function toArray(): array
    {
        return [
            'status' => $this->status,
            'source' => $this->source,
            'code' => $this->code,
            'message' => $this->message,
            'payload' => $this->payload,
        ];
    }
}
