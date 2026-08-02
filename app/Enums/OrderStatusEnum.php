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

enum OrderStatusEnum: string
{
    /** Operational lifecycle (orders table). */
    case CREATED = 'created';
    case IN_PROGRESS = 'in_progress';
    case READY = 'ready';
    case ISSUED = 'issued';
    case CLOSED = 'closed';
    case CANCELLED = 'cancelled';

    /** CRM / pipeline aliases stored in orders.status. */
    case DRAFT = 'draft';
    case PENDING = 'pending';
    case COMPLETED = 'completed';

    /** Offline-чек проведён с техническим овердрафтом склада. */
    case COMPLETED_WITH_OVERDRAFT = 'completed_with_overdraft';

    /** UI list filter tokens (query param `status`). */
    public const FILTER_IN_PROGRESS = 'in_progress';
    public const FILTER_READY = 'ready';
    public const FILTER_PAID = 'paid';

    /**
     * CRM-facing status aliases matching frontend DsBadge variants.
     */
    public static function fromCrm(string $value): ?self
    {
        return match ($value) {
            'accepted' => self::COMPLETED,
            'negotiation' => self::PENDING,
            'review', 'under_review' => self::IN_PROGRESS,
            'prospective' => self::DRAFT,
            'rejected' => self::CANCELLED,
            default => self::tryFrom($value),
        };
    }

    /**
     * @return list<string>|null
     */
    public static function valuesForListFilter(string $filter): ?array
    {
        return match ($filter) {
            self::FILTER_IN_PROGRESS => [self::CREATED->value, self::IN_PROGRESS->value],
            self::FILTER_READY => [self::READY->value, self::ISSUED->value],
            default => null,
        };
    }

    public static function isPaidListFilter(string $filter): bool
    {
        return $filter === self::FILTER_PAID;
    }

    /**
     * Frontend fulfillment bucket for orders list.
     */
    public static function fulfillmentBucket(?string $status): string
    {
        $enum = self::tryFrom((string) $status);

        return match ($enum) {
            self::READY, self::ISSUED => 'ready',
            self::CLOSED, self::COMPLETED => 'done',
            self::CREATED, self::IN_PROGRESS => 'in_progress',
            default => 'assembled',
        };
    }

    /**
     * Human-readable label matching frontend DsBadge variants.
     */
    public function label(): string
    {
        return match ($this) {
            self::DRAFT => 'Prospective',
            self::PENDING => 'In Negotiation',
            self::IN_PROGRESS => 'Under Review',
            self::COMPLETED => 'Accepted',
            self::CANCELLED => 'Rejected',
            self::CREATED => 'Created',
            self::READY => 'Ready',
            self::ISSUED => 'Issued',
            self::CLOSED => 'Closed',
        };
    }

    /**
     * DsBadge variant mapping.
     */
    public function variant(): string
    {
        return match ($this) {
            self::COMPLETED => 'success',
            self::CANCELLED => 'danger',
            self::IN_PROGRESS => 'warning',
            self::PENDING => 'pending',
            self::DRAFT => 'neutral',
            self::READY, self::ISSUED => 'open',
            self::CLOSED => 'success',
            self::CREATED => 'neutral',
        };
    }
}
