<?php

declare(strict_types=1);

namespace App\Models\Scopes;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Глобальная изоляция по tenant_id (BelongsToTenant).
 */
final class BelongsToTenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $tenantId = function_exists('tenant_id') ? tenant_id() : null;

        if ($tenantId === null) {
            $builder->whereRaw('1 = 0');

            return;
        }

        $builder->where($model->getTable().'.tenant_id', $tenantId);
    }
}
