<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Models;

class MarkingValidation extends TenantModel
{
    public const UPDATED_AT = 'updated_at';

    protected $table = 'marking_validations';

    protected $fillable = [
        'tenant_id',
        'marking_code',
        'gtin',
        'status',
        'response_payload',
        'created_at',
        'updated_at',
    ];

    protected $casts = [
        'response_payload' => 'array',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];
}
