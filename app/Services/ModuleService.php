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

namespace Autometria\Services;

use Autometria\Models\Module;
use Autometria\Support\AuditLog;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class ModuleService
{
    public function enable(int $tenantId, string $slug, int $userId): Module
    {
        return DB::transaction(function () use ($tenantId, $slug, $userId): Module {
            $module = Module::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('slug', $slug)
                ->firstOrFail();

            $old = ['status' => $module->status];

            $module->update([
                'status' => Module::STATUS_ACTIVE,
                'enabled_at' => now(),
                'disabled_at' => null,
            ]);

            AuditLog::write(
                $tenantId,
                $userId,
                'module.enabled',
                Module::class,
                (int) $module->id,
                $old,
                ['status' => Module::STATUS_ACTIVE, 'settings' => $module->settings],
            );

            return $module->fresh();
        });
    }

    public function disable(int $tenantId, string $slug, int $userId): Module
    {
        return DB::transaction(function () use ($tenantId, $slug, $userId): Module {
            $module = Module::query()->withoutGlobalScopes()
                ->where('tenant_id', $tenantId)
                ->where('slug', $slug)
                ->firstOrFail();

            if ($module->status === Module::STATUS_BLOCKED) {
                throw new InvalidArgumentException('Blocked module cannot be toggled by tenant');
            }

            $old = ['status' => $module->status, 'settings' => $module->settings];

            $module->update([
                'status' => Module::STATUS_DISABLED,
                'disabled_at' => now(),
            ]);

            // Данные settings сохраняются при отключении
            AuditLog::write(
                $tenantId,
                $userId,
                'module.disabled',
                Module::class,
                (int) $module->id,
                $old,
                ['status' => Module::STATUS_DISABLED, 'settings' => $module->settings],
            );

            return $module->fresh();
        });
    }
}
