<?php

namespace App\Http\Middleware;

use App\Models\Role;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use function config;
use function abort;

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
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null) {
            return $next($request);
        }

        $user->loadMissing('roles');

        if ($this->isSuperAdmin($user)) {
            return $next($request);
        }

        $tenantId = $request->headers->get('X-Tenant-ID');

        if ($tenantId === null || $tenantId === '') {
            abort(Response::HTTP_FORBIDDEN, 'Missing X-Tenant-ID header.');
        }

        if (! $this->userBelongsToTenant($user, $tenantId)) {
            abort(Response::HTTP_FORBIDDEN, 'The authenticated user does not belong to the selected tenant.');
        }

        $request->attributes->set('tenant_id', $tenantId);
        config(['tenant.id' => $tenantId]);

        return $next($request);
    }

    /**
     * Determine if the authenticated user has the superadmin role.
     */
    private function isSuperAdmin(User $user): bool
    {
        return $user->roles->contains(fn (Role $role): bool => $role->code === 'superadmin');
    }

    /**
     * Check whether the user is associated with the provided tenant identifier.
     */
    private function userBelongsToTenant(User $user, string $tenantId): bool
    {
        if ((string) $user->tenant_id === (string) $tenantId) {
            return true;
        }

        return $user->roles->contains(function (Role $role) use ($tenantId): bool {
            $assignedTenantId = $role->pivot->tenant_id ?? null;

            return (string) $assignedTenantId === (string) $tenantId;
        });
    }
}
