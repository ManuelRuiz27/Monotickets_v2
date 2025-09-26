<?php

namespace App\Policies;

use App\Models\Event;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Authorization policy for event management.
 */
class EventPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any events.
     */
    public function viewAny(User $user): bool
    {
        $user->loadMissing('roles');

        return $this->isSuperAdmin($user) || $this->hasRole($user, 'organizer');
    }

    /**
     * Determine whether the user can view the given event.
     */
    public function view(User $user, Event $event): bool
    {
        $user->loadMissing('roles');

        if ($this->isSuperAdmin($user)) {
            return true;
        }

        if (! $this->hasRole($user, 'organizer')) {
            return false;
        }

        return $this->isSameTenant($user, (string) $event->tenant_id);
    }

    /**
     * Determine whether the user can create events.
     */
    public function create(User $user): bool
    {
        $user->loadMissing('roles');

        return $this->isSuperAdmin($user) || $this->hasRole($user, 'organizer');
    }

    /**
     * Determine whether the user can update the given event.
     */
    public function update(User $user, Event $event): bool
    {
        $user->loadMissing('roles');

        if ($this->isSuperAdmin($user)) {
            return true;
        }

        if (! $this->hasRole($user, 'organizer')) {
            return false;
        }

        return $this->isSameTenant($user, (string) $event->tenant_id);
    }

    /**
     * Determine whether the user can delete the given event.
     */
    public function delete(User $user, Event $event): bool
    {
        return $this->update($user, $event);
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
        $tenantContext = config('tenant.id');

        if ($tenantContext !== null && $tenantContext !== '') {
            return (string) $tenantId === (string) $tenantContext;
        }

        return (string) $user->tenant_id === (string) $tenantId;
    }
}
