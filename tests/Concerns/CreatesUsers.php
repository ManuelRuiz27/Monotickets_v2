<?php

namespace Tests\Concerns;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;

/**
 * Helper methods for generating users with expected roles during tests.
 */
trait CreatesUsers
{
    /**
     * Create a superadmin user without a tenant assignment.
     */
    protected function createSuperAdmin(): User
    {
        $role = Role::factory()->create([
            'code' => 'superadmin',
            'tenant_id' => null,
        ]);

        $user = User::factory()->create(['tenant_id' => null]);
        $user->roles()->attach($role->id, ['tenant_id' => null]);

        return $user->fresh();
    }

    /**
     * Create an organizer bound to the provided tenant.
     */
    protected function createOrganizer(?Tenant $tenant = null): User
    {
        $tenant ??= Tenant::factory()->create();

        $role = Role::factory()->create([
            'code' => 'organizer',
            'tenant_id' => $tenant->id,
        ]);

        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->roles()->attach($role->id, ['tenant_id' => $tenant->id]);

        return $user->fresh();
    }

    /**
     * Create a hostess bound to the provided tenant.
     */
    protected function createHostess(?Tenant $tenant = null): User
    {
        $tenant ??= Tenant::factory()->create();

        $role = Role::factory()->create([
            'code' => 'hostess',
            'tenant_id' => $tenant->id,
        ]);

        $user = User::factory()->create(['tenant_id' => $tenant->id]);
        $user->roles()->attach($role->id, ['tenant_id' => $tenant->id]);

        return $user->fresh();
    }
}
