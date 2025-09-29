<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;
use App\Models\Venue;
use App\Support\TenantContext;
use Illuminate\Auth\Access\HandlesAuthorization;
use function app;

/**
 * Authorization policy for venue management.
 */
class VenuePolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any venues.
     */
    public function viewAny(User $user): bool
    {
        $user->loadMissing('roles');

        return $this->isSuperAdmin($user) || $this->hasRole($user, 'organizer');
    }

    /**
     * Determine whether the user can view the given venue.
     */
    public function view(User $user, Venue $venue): bool
    {
        $user->loadMissing('roles');
        $venue->loadMissing('event');

        if ($this->isSuperAdmin($user)) {
            return true;
        }

        if (! $this->hasRole($user, 'organizer')) {
            return false;
        }

        $tenantId = optional($venue->event)->tenant_id;

        return $tenantId !== null && $this->isSameTenant($user, (string) $tenantId);
    }

    /**
     * Determine whether the user can create venues.
     */
    public function create(User $user): bool
    {
        $user->loadMissing('roles');

        return $this->isSuperAdmin($user) || $this->hasRole($user, 'organizer');
    }

    /**
     * Determine whether the user can update the given venue.
     */
    public function update(User $user, Venue $venue): bool
    {
        return $this->view($user, $venue);
    }

    /**
     * Determine whether the user can delete the given venue.
     */
    public function delete(User $user, Venue $venue): bool
    {
        return $this->view($user, $venue);
    }

    /**
     * Check if the user has the superadmin role.
     */
    private function isSuperAdmin(User $user): bool
    {
        return $this->hasRole($user, 'superadmin');
    }

    /**
     * Determine if the user has the specified role.
     */
    private function hasRole(User $user, string $role): bool
    {
        return $user->roles->contains(fn (Role $assignedRole): bool => $assignedRole->code === $role);
    }

    /**
     * Check if the user shares the same tenant context as the resource.
     */
    private function isSameTenant(User $user, string $tenantId): bool
    {
        $tenantContext = app(TenantContext::class);

        if ($tenantContext->hasTenant()) {
            return (string) $tenantId === (string) $tenantContext->tenantId();
        }

        return (string) $user->tenant_id === (string) $tenantId;
    }
}
