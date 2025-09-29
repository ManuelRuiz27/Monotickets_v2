<?php

namespace App\Models\Scopes;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use function app;

class EventTenantScope implements Scope
{
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if (! $context->hasTenant()) {
            return;
        }

        $builder->whereHas('event', function (Builder $query) use ($context): void {
            $query->where('tenant_id', $context->tenantId());
        });
    }
}
