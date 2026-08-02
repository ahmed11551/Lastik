<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Enums;

enum InventoryDocumentTypeEnum: string
{
    /** Frontend contract values */
    case RECEIPT = 'RECEIPT';
    case WRITE_OFF = 'WRITE_OFF';
    case TRANSFER = 'TRANSFER';
    case INVENTORY = 'INVENTORY';

    /** Backend aliases from Block 4.2 brief */
    case INCOMING = 'INCOMING';
    case WRITEOFF = 'WRITEOFF';
    case AUDIT = 'AUDIT';

    public function canonical(): self
    {
        return match ($this) {
            self::INCOMING => self::RECEIPT,
            self::WRITEOFF => self::WRITE_OFF,
            self::AUDIT => self::INVENTORY,
            default => $this,
        };
    }

    public static function normalize(string $value): self
    {
        $enum = self::tryFrom(strtoupper(trim($value)));
        if ($enum === null) {
            throw new \InvalidArgumentException("Unknown inventory document type: {$value}");
        }

        return $enum->canonical();
    }

    /**
     * @return list<string>
     */
    public static function apiValues(): array
    {
        return [
            self::RECEIPT->value,
            self::WRITE_OFF->value,
            self::TRANSFER->value,
            self::INVENTORY->value,
            self::INCOMING->value,
            self::WRITEOFF->value,
            self::AUDIT->value,
        ];
    }
}
