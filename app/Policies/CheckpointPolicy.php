<?php

namespace App\Policies;

use App\Models\Checkpoint;
use App\Models\Role;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Auth\Access\HandlesAuthorization;
use function app;

/**
 * Authorization policy for checkpoint management.
 */
class CheckpointPolicy
{
    use HandlesAuthorization;

    /**
     * Determine whether the user can view any checkpoints.
     */
    public function viewAny(User $user): bool
    {
        $user->loadMissing('roles');

        return $this->isSuperAdmin($user) || $this->hasRole($user, 'organizer');
    }

    /**
     * Determine whether the user can view the given checkpoint.
     */
    public function view(User $user, Checkpoint $checkpoint): bool
    {
        $user->loadMissing('roles');
        $checkpoint->loadMissing('event');

        if ($this->isSuperAdmin($user)) {
            return true;
        }

        if (! $this->hasRole($user, 'organizer')) {
            return false;
        }

        $tenantId = optional($checkpoint->event)->tenant_id;

        return $tenantId !== null && $this->isSameTenant($user, (string) $tenantId);
    }

    /**
     * Determine whether the user can create checkpoints.
     */
    public function create(User $user): bool
    {
        $user->loadMissing('roles');

        return $this->isSuperAdmin($user) || $this->hasRole($user, 'organizer');
    }

    /**
     * Determine whether the user can update the given checkpoint.
     */
    public function update(User $user, Checkpoint $checkpoint): bool
    {
        return $this->view($user, $checkpoint);
    }

    /**
     * Determine whether the user can delete the given checkpoint.
     */
    public function delete(User $user, Checkpoint $checkpoint): bool
    {
        return $this->view($user, $checkpoint);
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
