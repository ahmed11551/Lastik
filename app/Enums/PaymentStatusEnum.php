<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @package    Autometria\Enums
 * @copyright  (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author     Себиев Ахмед Сулейманович
 * @license    Proprietary & Confidential.
 */

declare(strict_types=1);

namespace Autometria\Enums;

enum PaymentStatusEnum: string
{
    case PAID = 'paid';
    case PARTIAL = 'partial';
    case UNPAID = 'unpaid';

    /**
     * Map API / UI payment filter tokens to stored payment_status values.
     */
    public static function fromApiFilter(string $value): ?self
    {
        return match (strtolower(trim($value))) {
            'paid' => self::PAID,
            'partial' => self::PARTIAL,
            'debt', 'unpaid' => self::UNPAID,
            default => self::tryFrom($value),
        };
    }

    /**
     * Frontend bucket label for orders list (paid / partial / debt).
     */
    public function toApiBucket(): string
    {
        return match ($this) {
            self::PAID => 'paid',
            self::PARTIAL => 'partial',
            self::UNPAID => 'debt',
        };
    }

    public static function toApiBucketFromStored(?string $stored): string
    {
        return self::tryFrom((string) $stored)?->toApiBucket() ?? self::UNPAID->toApiBucket();
    }
}
