<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @copyright (c) 2026 Себиев Ахмед Сулейманович. All Rights Reserved.
 */

declare(strict_types=1);

namespace Autometria\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MarkingCode extends TenantModel
{
    protected $table = 'marking_codes';

    protected $fillable = [
        'tenant_id',
        'code',
        'gtin',
        'serial',
        'status',
        'product_id',
        'receipt_id',
    ];

    public function product(): BelongsTo
    {
        return $this->belongsTo(ProductService::class, 'product_id');
    }

    public function receipt(): BelongsTo
    {
        return $this->belongsTo(FiscalReceipt::class, 'receipt_id');
    }
}
