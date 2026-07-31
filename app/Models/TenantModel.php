<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

abstract class TenantModel extends Model
{
    protected static function booted(): void
    {
        static::addGlobalScope('tenant', new class implements Scope
        {
            public function apply(Builder $builder, Model $model): void
            {
                $tenantId = function_exists('tenant_id') ? tenant_id() : null;

                if ($tenantId === null) {
                    // Без tenant-контекста не отдаём чужие данные
                    $builder->whereRaw('1 = 0');

                    return;
                }

                $builder->where($model->getTable().'.tenant_id', $tenantId);
            }
        });
    }
}
