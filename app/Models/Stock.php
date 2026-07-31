<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Stock extends TenantModel
{
    protected $table = 'stocks';

    protected $fillable = [
        'tenant_id',
        'warehouse_id',
        'product_id',
        'actual',
        'reserved',
        'available',
    ];

    protected $casts = [
        'actual' => 'decimal:2',
        'reserved' => 'decimal:2',
        'available' => 'decimal:2',
    ];

    public function tenant(): BelongsTo
    {
        return $this->belongsTo(Tenant::class);
    }

    public function warehouse(): BelongsTo
    {
        return $this->belongsTo(Warehouse::class);
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(ProductService::class, 'product_id');
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(Reservation::class);
    }

    protected static function booted(): void
    {
        static::addGlobalScope('tenant', function (Builder $builder) {
            if (function_exists('tenant_id')) {
                $builder->where('stocks.tenant_id', tenant_id());
            }
        });
    }

    public function recalcAvailable(bool $save = false): void
    {
        $this->available = round($this->actual - $this->reserved, 2);

        if ($save) {
            $this->save();
        }
    }
}
