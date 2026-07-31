<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Dictionary extends TenantModel
{
    public const TYPE_ORDER_STATUS = 'order_status';

    public const TYPE_PAYMENT_STATUS = 'payment_status';

    public const TYPE_ITEM_STATUS = 'item_status';

    public const TYPE_PAYMENT_FORM = 'payment_form';

    public const TYPE_CANCEL_REASON = 'cancel_reason';

    public const TYPE_DELETE_REASON = 'delete_reason';

    public const TYPE_RETURN_REASON = 'return_reason';

    public const TYPE_CORRECTION_REASON = 'correction_reason';

    protected $table = 'dictionaries';

    protected $fillable = [
        'type',
        'code',
        'label',
        'sort',
        'is_active',
        'meta',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'meta' => 'array',
        'sort' => 'integer',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
