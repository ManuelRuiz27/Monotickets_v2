<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authorise API calls by matching the authenticated user's role.
 */
class RoleMiddleware
{
    /**
     * Allowed application roles.
     */
    private const ALLOWED_ROLES = ['superadmin', 'organizer', 'hostess'];

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        $user = $request->user();
        $validRoles = $roles ?: self::ALLOWED_ROLES;

        if (! $user || ! in_array($user->role ?? null, $validRoles, true)) {
            abort(Response::HTTP_FORBIDDEN, 'This action is unauthorised.');
        }

        return $next($request);
    }
}
