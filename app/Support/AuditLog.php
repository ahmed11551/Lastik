<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\AuditLog as AuditLogModel;
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
