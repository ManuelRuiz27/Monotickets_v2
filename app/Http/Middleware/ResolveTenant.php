<?php

namespace App\Http\Middleware;

use App\Models\AuditLog;
use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use function abort;
use function now;

class ResolveTenant
{
    public function __construct(private readonly TenantContext $tenantContext)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        /** @var User|null $user */
        $user = $request->user();
        $tenantId = $request->headers->get('X-Tenant-ID');

        config(['tenant.id' => null]);

        if ($user === null) {
            if ($tenantId !== null && $tenantId !== '') {
                $tenant = Tenant::query()->find($tenantId);

                if ($tenant !== null) {
                    $this->setTenantContext($request, $tenant);
                }
            }

            return $next($request);
        }

        $user->loadMissing('roles');

        $isSuperAdmin = $user->roles->contains(fn (Role $role): bool => $role->code === 'superadmin');

        if ($tenantId === null || $tenantId === '') {
            if ($isSuperAdmin) {
                return $next($request);
            }

            abort(Response::HTTP_FORBIDDEN, 'Missing X-Tenant-ID header.');
        }

        $tenant = Tenant::query()->find($tenantId);

        if ($tenant === null) {
            abort(Response::HTTP_FORBIDDEN, 'The selected tenant does not exist.');
        }

        if (! $isSuperAdmin && ! $this->userBelongsToTenant($user, (string) $tenant->id)) {
            abort(Response::HTTP_FORBIDDEN, 'The authenticated user does not belong to the selected tenant.');
        }

        if ($isSuperAdmin) {
            $this->logImpersonation($request, $user, $tenant->id);
        }

        $this->setTenantContext($request, $tenant);

        return $next($request);
    }

    private function userBelongsToTenant(User $user, string $tenantId): bool
    {
        if ((string) $user->tenant_id === $tenantId) {
            return true;
        }

        return $user->roles->contains(function (Role $role) use ($tenantId): bool {
            $assignedTenantId = $role->pivot->tenant_id ?? null;

            return (string) $assignedTenantId === $tenantId;
        });
    }

    private function logImpersonation(Request $request, User $user, string $tenantId): void
    {
        if ($request->attributes->get('_impersonated_tenant_id') === $tenantId) {
            return;
        }

        AuditLog::create([
            'tenant_id' => $tenantId,
            'user_id' => $user->id,
            'entity' => 'tenant',
            'entity_id' => $tenantId,
            'action' => 'impersonate_tenant',
            'diff_json' => ['impersonated_tenant_id' => $tenantId],
            'ip' => $request->ip(),
            'ua' => $request->userAgent(),
            'occurred_at' => now(),
        ]);

        $request->attributes->set('_impersonated_tenant_id', $tenantId);
    }

    private function setTenantContext(Request $request, Tenant $tenant): void
    {
        $this->tenantContext->setTenant($tenant);
        $request->attributes->set('tenant_id', (string) $tenant->id);
        config(['tenant.id' => (string) $tenant->id]);
    }
}
