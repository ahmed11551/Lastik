<?php

declare(strict_types=1);

namespace App\Models;

use RuntimeException;

class AuditLog extends TenantModel
{
    protected $table = 'audit_logs';

    public $timestamps = false;

    protected $fillable = [
        'tenant_id',
        'user_id',
        'action',
        'object_type',
        'object_id',
        'old',
        'new',
        'metadata',
        'ip',
        'user_agent',
        'reason',
        'created_at',
    ];

    protected $casts = [
        'old' => 'array',
        'new' => 'array',
        'metadata' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        parent::booted();

        static::updating(function (): void {
            throw new RuntimeException('audit_logs is append-only');
        });

        static::deleting(function (): void {
            throw new RuntimeException('audit_logs is append-only');
        });
    }
}
