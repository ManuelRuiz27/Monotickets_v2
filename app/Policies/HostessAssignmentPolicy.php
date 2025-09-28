<?php

namespace App\Policies;

use App\Models\HostessAssignment;
use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Authorization policy for hostess assignment management.
 */
class HostessAssignmentPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any assignments.
     */
    public function viewAny(User $user): bool
    {
        $user->loadMissing('roles');

        return $this->isSuperAdmin($user) || $this->hasRole($user, 'organizer');
    }

    /**
     * Determine whether the user can view the given assignment.
     */
    public function view(User $user, HostessAssignment $assignment): bool
    {
        $user->loadMissing('roles');

        if ($this->isSuperAdmin($user)) {
            return true;
        }

        if (! $this->hasRole($user, 'organizer')) {
            return false;
        }

        return $this->isSameTenant($user, (string) $assignment->tenant_id);
    }

    /**
     * Determine whether the user can create assignments.
     */
    public function create(User $user): bool
    {
        $user->loadMissing('roles');

        return $this->isSuperAdmin($user) || $this->hasRole($user, 'organizer');
    }

    /**
     * Determine whether the user can update the given assignment.
     */
    public function update(User $user, HostessAssignment $assignment): bool
    {
        return $this->view($user, $assignment);
    }

    /**
     * Determine whether the user can delete the given assignment.
     */
    public function delete(User $user, HostessAssignment $assignment): bool
    {
        return $this->view($user, $assignment);
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
     * Check if the user shares the same tenant context as the assignment.
     */
    private function isSameTenant(User $user, string $tenantId): bool
    {
        $tenantContext = config('tenant.id');

        if ($tenantContext !== null && $tenantContext !== '') {
            return (string) $tenantContext === (string) $tenantId;
        }

        return (string) $user->tenant_id === (string) $tenantId;
    }
}
