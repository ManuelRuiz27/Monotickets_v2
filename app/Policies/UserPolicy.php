<?php

namespace App\Policies;

use App\Models\Role;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

/**
 * Authorisation policy for user management.
 */
class UserPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any users.
     */
    public function viewAny(User $user): bool
    {
        $user->loadMissing('roles');

        return $this->isSuperAdmin($user) || $this->hasRole($user, 'organizer');
    }

    /**
     * Determine whether the user can view the given model.
     */
    public function view(User $user, User $model): bool
    {
        $user->loadMissing('roles');

        if ($this->isSuperAdmin($user)) {
            return true;
        }

        if (! $this->hasRole($user, 'organizer')) {
            return false;
        }

        return $this->isSameTenant($user, $model);
    }

    /**
     * Determine whether the user can create users.
     */
    public function create(User $user): bool
    {
        $user->loadMissing('roles');

        return $this->isSuperAdmin($user) || $this->hasRole($user, 'organizer');
    }

    /**
     * Determine whether the user can update the given model.
     */
    public function update(User $user, User $model): bool
    {
        $user->loadMissing('roles');

        if ($this->isSuperAdmin($user)) {
            return true;
        }

        if (! $this->hasRole($user, 'organizer')) {
            return false;
        }

        return $this->isSameTenant($user, $model);
    }

    /**
     * Determine whether the user can delete the given model.
     */
    public function delete(User $user, User $model): bool
    {
        $user->loadMissing('roles');

        if ($this->isSuperAdmin($user)) {
            return true;
        }

        if (! $this->hasRole($user, 'organizer')) {
            return false;
        }

        return $this->isSameTenant($user, $model) && $user->id !== $model->id;
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
     * Check if both users belong to the same tenant context.
     */
    private function isSameTenant(User $user, User $model): bool
    {
        $tenantContext = config('tenant.id');

        if ($tenantContext !== null && $tenantContext !== '') {
            return (string) $model->tenant_id === (string) $tenantContext;
        }

        return (string) $user->tenant_id === (string) $model->tenant_id;
    }
}

