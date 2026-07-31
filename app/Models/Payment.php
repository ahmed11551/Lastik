<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Payment extends TenantModel
{
    protected $table = 'payments';

    protected $fillable = [
        'order_id',
        'shift_id',
        'method',
        'type',
        'amount',
        'status',
        'payee_id',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(CashShift::class);
    }

    public function payee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payee_id');
    }

    public function corrections(): HasMany
    {
        return $this->hasMany(PaymentCorrection::class);
    }
}
