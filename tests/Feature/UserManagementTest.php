<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenant.id' => null]);
    }

    public function test_superadmin_can_list_users_with_filters(): void
    {
        $tenant = Tenant::factory()->create();

        $superAdmin = $this->createSuperAdmin();
        $organizerRole = Role::factory()->create(['code' => 'organizer', 'tenant_id' => $tenant->id]);
        $hostessRole = Role::factory()->create(['code' => 'hostess', 'tenant_id' => $tenant->id]);

        $hostess = User::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Host Example',
            'email' => 'host@example.com',
            'is_active' => true,
        ]);
        $hostess->roles()->attach($hostessRole->id, ['tenant_id' => $tenant->id]);

        $inactive = User::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Inactive Host',
            'email' => 'inactive@example.com',
            'is_active' => false,
        ]);
        $inactive->roles()->attach($hostessRole->id, ['tenant_id' => $tenant->id]);

        $otherTenant = Tenant::factory()->create();
        $otherRole = Role::factory()->create(['code' => 'hostess', 'tenant_id' => $otherTenant->id]);
        $otherUser = User::factory()->create([
            'tenant_id' => $otherTenant->id,
            'name' => 'Other Host',
            'email' => 'other@example.com',
            'is_active' => true,
        ]);
        $otherUser->roles()->attach($otherRole->id, ['tenant_id' => $otherTenant->id]);

        $response = $this->actingAs($superAdmin, 'api')->getJson('/users?role=hostess&is_active=1&search=Host');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $response->assertJsonPath('data.0.email', 'host@example.com');
        $response->assertJsonPath('meta.total', 1);
    }

    public function test_organizer_only_sees_users_in_tenant(): void
    {
        $tenantA = Tenant::factory()->create();
        $tenantB = Tenant::factory()->create();

        $organizerRole = Role::factory()->create(['code' => 'organizer', 'tenant_id' => $tenantA->id]);
        $hostessRoleA = Role::factory()->create(['code' => 'hostess', 'tenant_id' => $tenantA->id]);
        $hostessRoleB = Role::factory()->create(['code' => 'hostess', 'tenant_id' => $tenantB->id]);

        $organizer = User::factory()->create(['tenant_id' => $tenantA->id]);
        $organizer->roles()->attach($organizerRole->id, ['tenant_id' => $tenantA->id]);

        $tenantAUser = User::factory()->create(['tenant_id' => $tenantA->id, 'email' => 'tenant-a@example.com']);
        $tenantAUser->roles()->attach($hostessRoleA->id, ['tenant_id' => $tenantA->id]);

        $tenantBUser = User::factory()->create(['tenant_id' => $tenantB->id, 'email' => 'tenant-b@example.com']);
        $tenantBUser->roles()->attach($hostessRoleB->id, ['tenant_id' => $tenantB->id]);

        $response = $this->actingAs($organizer, 'api')
            ->withHeaders(['X-Tenant-ID' => $tenantA->id])
            ->getJson('/users');

        $response->assertOk();
        $this->assertTrue(collect($response->json('data'))
            ->every(fn (array $user) => $user['tenant_id'] === $tenantA->id));
    }

    public function test_organizer_cannot_assign_superadmin_role(): void
    {
        $tenant = Tenant::factory()->create();
        $organizerRole = Role::factory()->create(['code' => 'organizer', 'tenant_id' => $tenant->id]);

        $organizer = User::factory()->create(['tenant_id' => $tenant->id]);
        $organizer->roles()->attach($organizerRole->id, ['tenant_id' => $tenant->id]);

        $response = $this->actingAs($organizer, 'api')
            ->withHeaders(['X-Tenant-ID' => $tenant->id])
            ->postJson('/users', [
                'name' => 'Attempted Superadmin',
                'email' => 'attempt@example.com',
                'password' => 'password123',
                'roles' => ['superadmin'],
            ]);

        $response->assertForbidden();
        $this->assertDatabaseMissing('users', ['email' => 'attempt@example.com']);
    }

    public function test_superadmin_can_create_user_with_roles(): void
    {
        $tenant = Tenant::factory()->create();

        $superAdmin = $this->createSuperAdmin();
        $organizerRole = Role::factory()->create(['code' => 'organizer', 'tenant_id' => $tenant->id]);
        $hostessRole = Role::factory()->create(['code' => 'hostess', 'tenant_id' => $tenant->id]);

        $payload = [
            'name' => 'New Organizer',
            'email' => 'new.organizer@example.com',
            'password' => 'securePass123',
            'roles' => ['organizer', 'hostess'],
            'is_active' => true,
        ];

        $response = $this->actingAs($superAdmin, 'api')
            ->withHeaders(['X-Tenant-ID' => $tenant->id])
            ->postJson('/users', $payload);

        $response->assertCreated();
        $response->assertJsonPath('data.name', 'New Organizer');
        $this->assertDatabaseHas('users', ['email' => 'new.organizer@example.com', 'tenant_id' => $tenant->id]);

        /** @var User $created */
        $created = User::where('email', 'new.organizer@example.com')->firstOrFail();

        $this->assertTrue($created->roles()->where('roles.code', 'organizer')->exists());
        $this->assertTrue($created->roles()->where('roles.code', 'hostess')->exists());

        $this->assertDatabaseHas('audit_logs', [
            'entity' => 'user',
            'entity_id' => $created->id,
            'action' => 'created',
        ]);
    }

    public function test_update_user_updates_roles_and_logs(): void
    {
        $tenant = Tenant::factory()->create();

        $superAdmin = $this->createSuperAdmin();
        $organizerRole = Role::factory()->create(['code' => 'organizer', 'tenant_id' => $tenant->id]);
        $hostessRole = Role::factory()->create(['code' => 'hostess', 'tenant_id' => $tenant->id]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'name' => 'Original User',
            'email' => 'original@example.com',
            'phone' => '555-0001',
        ]);
        $user->roles()->attach($hostessRole->id, ['tenant_id' => $tenant->id]);

        $response = $this->actingAs($superAdmin, 'api')
            ->withHeaders(['X-Tenant-ID' => $tenant->id])
            ->patchJson('/users/' . $user->id, [
                'name' => 'Updated User',
                'phone' => '555-9999',
                'roles' => ['organizer'],
            ]);

        $response->assertOk();
        $response->assertJsonPath('data.name', 'Updated User');
        $response->assertJsonPath('data.phone', '555-9999');
        $response->assertJsonPath('data.roles.0.code', 'organizer');

        $this->assertTrue($user->fresh()->roles()->where('roles.code', 'organizer')->exists());
        $this->assertDatabaseHas('audit_logs', [
            'entity' => 'user',
            'entity_id' => $user->id,
            'action' => 'updated',
        ]);
    }

    public function test_delete_user_soft_deletes_and_logs(): void
    {
        $tenant = Tenant::factory()->create();
        $superAdmin = $this->createSuperAdmin();
        $hostessRole = Role::factory()->create(['code' => 'hostess', 'tenant_id' => $tenant->id]);

        $user = User::factory()->create(['tenant_id' => $tenant->id, 'email' => 'delete.me@example.com']);
        $user->roles()->attach($hostessRole->id, ['tenant_id' => $tenant->id]);

        $response = $this->actingAs($superAdmin, 'api')
            ->withHeaders(['X-Tenant-ID' => $tenant->id])
            ->deleteJson('/users/' . $user->id);

        $response->assertNoContent();
        $this->assertSoftDeleted('users', ['id' => $user->id]);

        $this->assertDatabaseHas('audit_logs', [
            'entity' => 'user',
            'entity_id' => $user->id,
            'action' => 'deleted',
        ]);
    }

    private function createSuperAdmin(): User
    {
        $role = Role::factory()->create(['code' => 'superadmin', 'tenant_id' => null]);
        $user = User::factory()->create(['tenant_id' => null]);
        $user->roles()->attach($role->id, ['tenant_id' => null]);

        return $user->fresh();
    }
}

