<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @package    Autometria\Core
 * @copyright  (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 * @author     Себиев Ахмед Сулейманович (Chief Software Architect / Lead Developer)
 * @license    Proprietary & Confidential.
 */

declare(strict_types=1);

namespace Autometria\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OneCSyncLog extends TenantModel
{
    protected $table = 'one_c_sync_logs';

    protected $fillable = [
        'tenant_id',
        'direction',
        'channel',
        'file_name',
        'status',
        'processed_count',
        'payload_size',
        'errors',
        'details',
    ];

    protected $casts = [
        'processed_count' => 'integer',
        'payload_size' => 'integer',
        'details' => 'array',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
