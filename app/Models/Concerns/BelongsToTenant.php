<?php

declare(strict_types=1);

namespace App\Models\Concerns;

use App\Models\Scopes\BelongsToTenantScope;

/**
 * Tenant isolation: global scope (optional) + block HTTP mass-assignment of tenant_id / audit actors.
 */
trait BelongsToTenant
{
    /** @var list<string> */
    private const TENANT_MASS_ASSIGNMENT_BLOCKLIST = [
        'tenant_id',
        'created_by',
        'updated_by',
    ];

    public static function bootBelongsToTenant(): void
    {
        if (static::appliesTenantGlobalScope()) {
            static::addGlobalScope(new BelongsToTenantScope);
        }

        static::creating(function ($model): void {
            if ($model->getAttribute('tenant_id') !== null) {
                return;
            }

            $tenantId = function_exists('tenant_id') ? tenant_id() : null;
            if ($tenantId !== null) {
                $model->setAttribute('tenant_id', $tenantId);
            }
        });
    }

    /**
     * User/auth models must stay queryable before tenant context exists.
     */
    protected static function appliesTenantGlobalScope(): bool
    {
        return true;
    }

    /**
     * @return list<string>
     */
    public function getFillable(): array
    {
        $fillable = property_exists($this, 'fillable') && is_array($this->fillable)
            ? $this->fillable
            : [];

        return array_values(array_diff($fillable, self::TENANT_MASS_ASSIGNMENT_BLOCKLIST));
    }

    /**
     * Trusted writes from services/seeders (bypasses fillable blocklist).
     *
     * @param  array<string, mixed>  $attributes
     */
    public static function createForTenant(array $attributes): static
    {
        $model = new static;
        $model->forceFill($attributes);
        $model->save();

        return $model;
    }
}
