<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MoneyRecipient extends TenantModel
{
    public const TYPE_CASH_DESK = 'cash_desk';

    public const TYPE_CARD_FIO = 'card_fio';

    public const TYPE_IP = 'ip_account';

    public const TYPE_OOO = 'ooo_account';

    public const TYPE_OTHER = 'other';

    protected $table = 'money_recipients';

    protected $fillable = [
        'type',
        'name',
        'details',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }
}
