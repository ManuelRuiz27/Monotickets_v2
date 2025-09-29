<?php

namespace App\Http;

use Illuminate\Foundation\Http\Kernel as HttpKernel;

/**
 * Register global HTTP middleware stack for the API application.
 */
class Kernel extends HttpKernel
{
    /**
     * The application's route middleware groups.
     */
    protected $middlewareGroups = [
        'api' => [
            \App\Http\Middleware\EnsureHttps::class,
            \App\Http\Middleware\EnsureJsonRequest::class,
            'throttle:api',
            \Illuminate\Routing\Middleware\SubstituteBindings::class,
            \App\Http\Middleware\ResolveTenant::class,
            \App\Http\Middleware\SecureHeaders::class,
        ],
    ];

    /**
     * The application's route middleware.
     */
    protected $routeMiddleware = [
        'role' => \App\Http\Middleware\RoleMiddleware::class,
        'cache.dashboard' => \App\Http\Middleware\CacheDashboardResponses::class,
        'limits' => \App\Http\Middleware\EnforceLimits::class,
    ];
}
