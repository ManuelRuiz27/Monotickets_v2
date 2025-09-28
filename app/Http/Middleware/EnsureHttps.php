<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Block non-secure traffic to guarantee HTTPS-only access.
 */
class EnsureHttps
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (app()->runningUnitTests()) {
            return $next($request);
        }

        if ($request->isSecure()) {
            return $next($request);
        }

        if ($request->headers->get('X-Forwarded-Proto') === 'https') {
            return $next($request);
        }

        abort(403, 'Las comunicaciones deben realizarse a través de HTTPS.');
    }
}
