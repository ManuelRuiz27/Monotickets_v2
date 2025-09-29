<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Role;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use function app;

/**
 * Shared helpers for controllers operating under tenant context.
 */
trait InteractsWithTenants
{
    /**
     * Determine whether the authenticated user is a superadmin.
     */
    private function isSuperAdmin(User $user): bool
    {
        $user->loadMissing('roles');

        return $user->roles->contains(fn (Role $role): bool => $role->code === 'superadmin');
    }

    /**
     * Resolve the tenant identifier from the current request context.
     */
    private function resolveTenantContext(Request $request, User $authUser): ?string
    {
        $tenantContext = app(TenantContext::class);

        if ($tenantContext->hasTenant()) {
            return $tenantContext->tenantId();
        }

        $tenantId = (string) $request->attributes->get('tenant_id');

        if ($tenantId !== '') {
            return $tenantId;
        }

        $headerTenant = $request->headers->get('X-Tenant-ID');

        if ($headerTenant !== null && $headerTenant !== '') {
            return $headerTenant;
        }

        return $authUser->tenant_id !== null ? (string) $authUser->tenant_id : null;
    }

    /**
     * Throw a validation exception using the standard API response shape.
     *
     * @param  array<string, array<int, string>>  $errors
     */
    private function throwValidationException(array $errors): void
    {
        throw ValidationException::withMessages($errors);
    }
}
