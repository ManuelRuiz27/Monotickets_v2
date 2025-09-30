<?php

namespace App\Providers;

use App\Support\TenantContext;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(TenantContext::class, static fn (): TenantContext => new TenantContext());
    }

    public function boot(): void
    {
        Schema::defaultStringLength(191);
    }
}
