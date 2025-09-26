<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ensure that every request contains a tenant identifier header.
 */
class EnsureTenantHeader
{
    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->headers->has('X-Tenant-ID')) {
            abort(Response::HTTP_BAD_REQUEST, 'Missing X-Tenant-ID header.');
        }

        $tenantId = $request->header('X-Tenant-ID');

        $request->attributes->set('tenant_id', $tenantId);
        config(['tenant.id' => $tenantId]);

        return $next($request);
    }
}
