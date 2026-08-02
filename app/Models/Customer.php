<?php

/**
 * AUTOMETRIA ERP Engine Core
 *
 * @package    Autometria\Core
 * @copyright  (c) 2026 Себиев Ахмед Сулейманович (Sebiev Akhmed Suleymanovich). All Rights Reserved.
 * @author     Себиев Ахмед Сулейманович (Chief Software Architect / Lead Developer)
 * @license    Proprietary & Confidential.
 */

declare(strict_types=1);

namespace Autometria\Models;

use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Customer extends TenantModel
{
    public const TYPE_INDIVIDUAL = 'individual';

    public const TYPE_LEGAL = 'legal';

    protected $table = 'customers';

    protected $fillable = [
        'type',
        'name',
        'phone',
        'email',
        'inn',
        'kpp',
        'legal_name',
        'discount_card_number',
        'bonus_balance',
        'total_spent',
        'tier',
        'created_by',
    ];

    protected $casts = [
        'type' => 'string',
        'bonus_balance' => 'decimal:2',
        'total_spent' => 'decimal:2',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function vehicles(): HasMany
    {
        return $this->hasMany(Vehicle::class);
    }

    public function orders(): HasMany
    {
        return $this->hasMany(Order::class);
    }

    public function loyaltyTransactions(): HasMany
    {
        return $this->hasMany(LoyaltyTransaction::class);
    }
}
