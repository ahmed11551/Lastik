<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Models;

class EgaisDocument extends TenantModel
{
    protected $table = 'egais_documents';

    protected $fillable = [
        'tenant_id',
        'doc_type',
        'fsrar_id',
        'status',
        'payload',
    ];

    protected $casts = [
        'payload' => 'array',
    ];
}
