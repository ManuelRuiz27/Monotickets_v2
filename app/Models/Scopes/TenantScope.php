<?php

namespace App\Models\Scopes;

use App\Support\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use function app;

class TenantScope implements Scope
{
    public function __construct(private ?string $column = null)
    {
    }

    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if (! $context->hasTenant()) {
            return;
        }

        $column = $this->column ?? $model->getTable() . '.tenant_id';

        $builder->where($column, $context->tenantId());
    }
}
