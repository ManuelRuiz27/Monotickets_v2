<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Remove sensitive headers from responses and apply security defaults.
 */
class SecureHeaders
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var Response $response */
        $response = $next($request);

        $response->headers->remove('Server');
        $response->headers->remove('X-Powered-By');
        $response->headers->remove('X-PHP-Version');
        $response->headers->set('X-Content-Type-Options', 'nosniff', false);
        $response->headers->set('X-Frame-Options', 'DENY', false);

        if (function_exists('header_remove')) {
            header_remove('Server');
            header_remove('X-Powered-By');
            header_remove('X-PHP-Version');
        }

        return $response;
    }
}
