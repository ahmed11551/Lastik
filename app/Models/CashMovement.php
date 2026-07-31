<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CashMovement extends TenantModel
{
    const TYPE_INKASSO = 'inkasso';

    const TYPE_WITHDRAWAL = 'withdrawal';

    const TYPE_ADJUSTMENT = 'adjustment';

    protected $table = 'cash_movements';

    protected $fillable = [
        'tenant_id',
        'shift_id',
        'type',
        'amount',
        'payee_id',
        'reason',
        'note',
        'created_by',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function shift(): BelongsTo
    {
        return $this->belongsTo(CashShift::class);
    }

    public function payee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'payee_id');
    }
}
