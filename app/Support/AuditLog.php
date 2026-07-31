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

namespace Autometria\Support;

use Autometria\Models\AuditLog as AuditLogModel;
use Illuminate\Http\Request;

class AuditLog
{
    public static function write(
        int $tenantId,
        ?int $userId,
        string $action,
        string $objectType,
        ?int $objectId,
        array $old = [],
        array $new = [],
        array $metadata = [],
        ?string $reason = null
    ): AuditLogModel {
        $request = request();

        return AuditLogModel::query()->forceCreate([
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'action' => $action,
            'object_type' => $objectType,
            'object_id' => $objectId,
            'old' => $old !== [] ? $old : null,
            'new' => $new !== [] ? $new : null,
            'metadata' => $metadata !== [] ? $metadata : [],
            'ip' => $request instanceof Request ? $request->ip() : null,
            'user_agent' => $request instanceof Request ? $request->userAgent() : null,
            'reason' => $reason,
            'created_at' => now(),
        ]);
    }
}
