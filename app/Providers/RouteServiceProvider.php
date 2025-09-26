<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Routing\Router;

/**
 * Configure API route bindings and patterns.
 */
class RouteServiceProvider extends ServiceProvider
{
    /**
     * Define the routes for the application.
     */
    public function map(Router $router): void
    {
        $this->mapApiRoutes($router);
    }

    /**
     * Register the API routes for the application.
     */
    protected function mapApiRoutes(Router $router): void
    {
        $router->middleware('api')
            ->group(base_path('routes/api.php'));
    }
}
