<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Configure route related services for the application.
 */
class RouteServiceProvider extends ServiceProvider
{
    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();
    }

    /**
     * Configure dedicated rate limiters for authentication endpoints.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('auth-login', function (Request $request): Limit {
            $email = (string) $request->input('email');
            $identifier = $request->ip() . '|' . ($email !== '' ? strtolower($email) : 'guest');

            return Limit::perMinute(5)->by($identifier);
        });

        RateLimiter::for('auth-forgot', function (Request $request): Limit {
            $email = (string) $request->input('email');
            $identifier = $request->ip() . '|' . ($email !== '' ? strtolower($email) : 'guest');

            return Limit::perMinutes(5, 3)->by($identifier);
        });
    }
}
