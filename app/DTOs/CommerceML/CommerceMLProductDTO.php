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

namespace Autometria\DTOs\CommerceML;

use SimpleXMLElement;

readonly class CommerceMLProductDTO
{
    /**
     * @param  array<string, mixed>  $attributes
     */
    public function __construct(
        public string $externalId,
        public string $sku,
        public string $name,
        public ?string $description,
        public ?string $categoryExternalId,
        public ?string $unit,
        public array $attributes = [],
    ) {}

    public static function fromXMLNode(SimpleXMLElement $xml): self
    {
        return new self(
            externalId: (string) $xml->Ид,
            sku: (string) ($xml->Артикул ?? $xml->Ид),
            name: (string) $xml->Наименование,
            description: isset($xml->Описание) ? (string) $xml->Описание : null,
            categoryExternalId: isset($xml->Группы->Ид) ? (string) $xml->Группы->Ид : null,
            unit: isset($xml->БазоваяЕдиница) ? (string) $xml->БазоваяЕдиница : 'шт',
            attributes: [],
        );
    }
}
