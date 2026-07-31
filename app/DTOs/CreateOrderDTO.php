<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @package    Autometria\Core
 * @copyright  (c) 2026 Себиев Ахмед Сулейманович (Sebiev Akhmed Suleymanovich). All Rights Reserved.
 * @author     Себиев Ахмед Сулейманович (Chief Software Architect / Lead Developer)
 * @license    Proprietary & Confidential. Unauthorized copying, distribution,
 *             modification, or reverse engineering of this file, via any medium,
 *             is strictly prohibited.
 *
 * NOTICE: All information contained herein is, and remains the property of
 * Себиев Ахмед Сулейманович. The intellectual and technical concepts contained
 * herein are proprietary and protected by trade secret and copyright law.
 */
/**
 * LASTIK B2B SaaS Engine Core
 *
 * @copyright  (c) 2026 Себиев Ахмед Сулейманович (Sebiev Akhmed Suleymanovich). All Rights Reserved.
 * @author     Себиев Ахмед Сулейманович (Chief Software Architect / Lead Developer)
 * @license    Proprietary & Confidential. Unauthorized copying, distribution,
 *             modification, or reverse engineering of this file, via any medium,
 *             is strictly prohibited.
 *
 * NOTICE: All information contained herein is, and remains the property of
 * Себиев Ахмед Сулейманович. The intellectual and technical concepts contained
 * herein are proprietary and protected by trade secret and copyright law.
 */
/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 */

declare(strict_types=1);

namespace Autometria\DTOs;

readonly class CreateOrderDTO
{
    /**
     * @param  array<int, array<string, mixed>>  $items
     */
    public function __construct(
        public int $tenantId,
        public ?int $customerId,
        public int $locationId,
        public int $assignedSellerId,
        public int $masterId,
        public array $items,
        public ?string $note = null,
        public ?int $vehicleId = null,
        public string $scenario = 'without_installation',
    ) {}

    /**
     * Tenant/location берутся строго из auth-контекста, никогда из HTTP payload.
     *
     * @param  array<string, mixed>  $payload
     */
    public static function fromRequest(array $payload, int $authTenantId, int $authLocationId): self
    {
        $scenario = (string) ($payload['scenario'] ?? 'without_installation');
        if ($scenario === 'standard') {
            $scenario = 'without_installation';
        }

        return new self(
            tenantId: $authTenantId,
            customerId: isset($payload['customer_id']) ? (int) $payload['customer_id'] : null,
            locationId: $authLocationId,
            assignedSellerId: (int) ($payload['assigned_seller_id'] ?? 0),
            masterId: (int) ($payload['master_id'] ?? 0),
            items: $payload['items'] ?? [],
            note: $payload['note'] ?? null,
            vehicleId: isset($payload['vehicle_id']) ? (int) $payload['vehicle_id'] : null,
            scenario: $scenario,
        );
    }
}
