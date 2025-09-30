<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

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

        RateLimiter::for('auth-generic', function (Request $request): Limit {
            return Limit::perMinute(60)->by($request->ip());
        });

        RateLimiter::for('auth-forgot', function (Request $request): Limit {
            $email = (string) $request->input('email');
            $identifier = $request->ip() . '|' . ($email !== '' ? strtolower($email) : 'guest');

            return Limit::perMinutes(5, 3)->by($identifier);
        });

        RateLimiter::for('scan-device', function (Request $request): Limit {
            $deviceId = (string) $request->input('device_id');
            $tenantId = (string) $request->attributes->get('tenant_id');
            $identifier = $deviceId !== '' ? $deviceId : sprintf('ip:%s', $request->ip());

            if ($tenantId !== '') {
                $identifier = sprintf('%s|tenant:%s', $identifier, $tenantId);
            }

            $rate = (int) config('scan.device_rate_limit_per_second', 10);

            return Limit::perSecond(max(1, $rate))->by(Str::lower($identifier));
        });

        RateLimiter::for('reports-export', function (Request $request): Limit {
            $user = $request->user();
            $identifier = $user?->getAuthIdentifier();

            $key = $identifier !== null
                ? sprintf('user:%s', $identifier)
                : sprintf('ip:%s', $request->ip());

            return Limit::perMinute(1)->by(Str::lower((string) $key));
        });
    }
}
