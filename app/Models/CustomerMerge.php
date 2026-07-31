<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CustomerMerge extends TenantModel
{
    protected $table = 'customer_merges';

    protected $fillable = [
        'primary_customer_id',
        'merged_customer_id',
        'merged_by',
        'transferred',
        'reason',
    ];

    protected $casts = [
        'transferred' => 'array',
    ];

    public function primary(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'primary_customer_id');
    }

    public function merged(): BelongsTo
    {
        return $this->belongsTo(Customer::class, 'merged_customer_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'merged_by');
    }
}
