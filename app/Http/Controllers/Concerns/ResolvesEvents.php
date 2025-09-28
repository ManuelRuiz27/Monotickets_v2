<?php

namespace App\Http\Controllers\Concerns;

use App\Models\Event;
use App\Models\User;
use Illuminate\Http\Request;

/**
 * Helper methods for controllers that need to resolve events in a tenant context.
 */
trait ResolvesEvents
{
    use InteractsWithTenants;

    /**
     * Locate an event respecting the current tenant context.
     */
    private function findEventForRequest(Request $request, User $authUser, string $eventId): ?Event
    {
        $query = Event::query()->whereKey($eventId);
        $tenantId = $this->resolveTenantContext($request, $authUser);

        if ($this->isSuperAdmin($authUser)) {
            if ($tenantId !== null) {
                $query->where('tenant_id', $tenantId);
            }
        } else {
            if ($tenantId === null) {
                $this->throwValidationException([
                    'tenant_id' => ['Unable to determine tenant context.'],
                ]);
            }

            $query->where('tenant_id', $tenantId);
        }

        return $query->first();
    }
}

