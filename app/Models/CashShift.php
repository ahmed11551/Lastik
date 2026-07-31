<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CashShift extends TenantModel
{
    protected $table = 'cash_shifts';

    protected $fillable = [
        'tenant_id',
        'location_id',
        'user_id',
        'opened_by',
        'closed_by',
        'status',
        'opening_amount',
        'closing_amount',
        'opened_at',
        'closed_at',
        'totals',
        'note',
    ];

    protected $casts = [
        'totals' => 'array',
        'opening_amount' => 'decimal:2',
        'closing_amount' => 'decimal:2',
        'opened_at' => 'datetime',
        'closed_at' => 'datetime',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function cashMovements(): HasMany
    {
        return $this->hasMany(CashMovement::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }
}
