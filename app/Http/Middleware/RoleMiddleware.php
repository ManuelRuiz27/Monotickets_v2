<?php

namespace App\Http\Middleware;

use App\Models\Role;
use App\Models\User;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\Response;
use function abort;

/**
 * Authorise API calls by matching the authenticated user's role.
 */
class RoleMiddleware
{
    /**
     * Allowed application roles.
     */
    private const ALLOWED_ROLES = ['superadmin', 'organizer', 'hostess'];

    public function __construct(private readonly TenantContext $tenantContext)
    {
    }

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next, string ...$roles): Response
    {
        /** @var User|null $user */
        $user = $request->user();

        if ($user === null) {
            abort(Response::HTTP_FORBIDDEN, 'This action is unauthorised.');
        }

        $requiredRoles = $this->normaliseRoles($roles);

        $user->loadMissing('roles');

        if ($this->hasRole($user, 'superadmin')) {
            return $next($request);
        }

        $tenantId = $this->tenantContext->tenantId();

        if ($tenantId === null) {
            abort(Response::HTTP_FORBIDDEN, 'Missing tenant context.');
        }

        $hasRequiredRole = $user->roles->contains(function (Role $role) use ($tenantId, $requiredRoles): bool {
            if (! in_array($role->code, $requiredRoles, true)) {
                return false;
            }

            $assignedTenantId = $role->pivot->tenant_id ?? null;

            return (string) $assignedTenantId === (string) $tenantId;
        });

        if (! $hasRequiredRole) {
            abort(Response::HTTP_FORBIDDEN, 'This action is unauthorised.');
        }

        return $next($request);
    }

    /**
     * Determine if the user holds the given role code.
     */
    private function hasRole(User $user, string $role): bool
    {
        return $user->roles->contains(fn (Role $assignedRole): bool => $assignedRole->code === $role);
    }

    /**
     * Normalise a list of role slugs and apply defaults when none are provided.
     *
     * @param  array<int, string>  $roles
     * @return array<int, string>
     */
    private function normaliseRoles(array $roles): array
    {
        $roles = array_filter(array_map('strtolower', $roles));

        if ($roles === []) {
            return self::ALLOWED_ROLES;
        }

        $filtered = array_values(array_unique(Arr::where($roles, fn ($role) => in_array($role, self::ALLOWED_ROLES, true))));

        return $filtered;
    }
}
