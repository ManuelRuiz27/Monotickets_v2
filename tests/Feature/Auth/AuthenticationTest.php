<?php

namespace Tests\Feature\Auth;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenant.id' => null]);
    }

    public function test_user_can_login_with_valid_credentials(): void
    {
        $user = User::factory()->create([
            'tenant_id' => Tenant::factory()->create()->id,
            'email' => 'user@example.com',
            'password_hash' => Hash::make('password123'),
        ]);

        $response = $this->postJson('/auth/login', [
            'email' => 'user@example.com',
            'password' => 'password123',
        ]);

        $response->assertOk();
        $response->assertJsonStructure([
            'access_token',
            'token_type',
            'expires_in',
            'refresh_token',
            'refresh_expires_in',
            'session_id',
        ]);

        $this->assertDatabaseHas('sessions', [
            'user_id' => $user->id,
            'id' => $response->json('session_id'),
            'revoked_at' => null,
        ]);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'entity' => 'auth',
            'action' => 'login',
        ]);
    }

    public function test_login_fails_with_invalid_credentials(): void
    {
        User::factory()->create([
            'email' => 'invalid@example.com',
            'password_hash' => Hash::make('password123'),
        ]);

        $response = $this->postJson('/auth/login', [
            'email' => 'invalid@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertUnauthorized();
        $response->assertJsonPath('error.code', 'UNAUTHORIZED');
    }

    public function test_refresh_issues_new_tokens_for_valid_refresh_token(): void
    {
        $user = User::factory()->create([
            'tenant_id' => Tenant::factory()->create()->id,
            'email' => 'refresh@example.com',
            'password_hash' => Hash::make('password123'),
        ]);

        $loginResponse = $this->postJson('/auth/login', [
            'email' => 'refresh@example.com',
            'password' => 'password123',
        ])->assertOk();

        $refreshResponse = $this->postJson('/auth/refresh', [
            'refresh_token' => $loginResponse->json('refresh_token'),
        ]);

        $refreshResponse->assertOk();
        $refreshResponse->assertJsonStructure([
            'access_token',
            'token_type',
            'expires_in',
            'refresh_token',
            'refresh_expires_in',
            'session_id',
        ]);

        $this->assertNotSame(
            $loginResponse->json('access_token'),
            $refreshResponse->json('access_token')
        );

        $this->assertDatabaseHas('sessions', [
            'id' => $refreshResponse->json('session_id'),
            'user_id' => $user->id,
            'revoked_at' => null,
        ]);
    }

    public function test_logout_invalidates_token_and_records_audit_log(): void
    {
        $tenant = Tenant::factory()->create();
        $role = Role::factory()->create([
            'code' => 'organizer',
            'tenant_id' => $tenant->id,
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'logout@example.com',
            'password_hash' => Hash::make('password123'),
        ]);
        $user->roles()->attach($role->id, ['tenant_id' => $tenant->id]);

        $loginResponse = $this->postJson('/auth/login', [
            'email' => 'logout@example.com',
            'password' => 'password123',
        ])->assertOk();

        $accessToken = $loginResponse->json('access_token');
        $sessionId = $loginResponse->json('session_id');

        $logoutResponse = $this->withHeader('Authorization', 'Bearer ' . $accessToken)
            ->postJson('/auth/logout');

        $logoutResponse->assertOk();
        $logoutResponse->assertJsonPath('message', 'Logged out successfully.');

        $this->assertDatabaseHas('sessions', [
            'id' => $sessionId,
        ]);

        $session = $user->sessions()->whereKey($sessionId)->first();
        $this->assertNotNull($session);
        $this->assertNotNull($session->revoked_at);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'entity' => 'auth',
            'action' => 'logout',
            'entity_id' => $sessionId,
        ]);
    }
}
