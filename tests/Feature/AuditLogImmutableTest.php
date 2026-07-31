<?php

/*
 * AUTOMETRIA ERP Engine Core
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author Себиев Ахмед Сулейманович
 * @license Proprietary & Confidential.
 */

declare(strict_types=1);

use Autometria\Models\AuditLog;
use Tests\Support\AcceptanceFixture;

// 49.8: журнал аудита append-only — попытка изменить/удалить AuditLog падает.
test('audit log cannot be updated', function (): void {
    $fx = AcceptanceFixture::make('audit-'.uniqid());
    $log = AuditLog::query()->withoutGlobalScopes()->create([
        'tenant_id' => $fx->tenant->id, 'user_id' => $fx->user->id, 'action' => 'test.created',
        'object_type' => AuditLog::class, 'object_id' => 1, 'created_at' => now(),
    ]);

    expect(fn () => $log->update(['action' => 'forged']))->toThrow(RuntimeException::class);
});

test('audit log cannot be deleted', function (): void {
    $fx = AcceptanceFixture::make('audit-'.uniqid());
    $log = AuditLog::query()->withoutGlobalScopes()->create([
        'tenant_id' => $fx->tenant->id, 'user_id' => $fx->user->id, 'action' => 'test.created',
        'object_type' => AuditLog::class, 'object_id' => 1, 'created_at' => now(),
    ]);

    expect(fn () => $log->delete())->toThrow(RuntimeException::class);
});
