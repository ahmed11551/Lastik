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

namespace Autometria\Services\Import;

class ValidationRules
{
    public function validateRow(array $row): array
    {
        $errors = [];

        if (empty($row['type'])) {
            $errors['type'] = 'Customer type is required';
        } elseif (! in_array($row['type'], ['individual', 'legal'], true)) {
            $errors['type'] = 'Invalid customer type';
        }

        if (empty($row['phone'])) {
            $errors['phone'] = 'Phone is required';
        }

        if (isset($row['inn']) && $row['inn'] !== '' && preg_match('/^\d{10,12}$/', (string) $row['inn']) !== 1) {
            $errors['inn'] = 'INN must be 10 or 12 digits';
        }

        if (isset($row['email']) && $row['email'] !== '' && filter_var($row['email'], FILTER_VALIDATE_EMAIL) === false) {
            $errors['email'] = 'Email is invalid';
        }

        return $errors;
    }
}
