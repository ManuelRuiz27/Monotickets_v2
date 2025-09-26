<?php

namespace Tests\Feature\Auth;

use App\Models\Role;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
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
        $this->clearRateLimiter('auth-login', 'user@example.com');

        $user = User::factory()->create([
            'tenant_id' => Tenant::factory()->create()->id,
            'email' => 'user@example.com',
            'password_hash' => Hash::make('Password123!'),
        ]);

        $response = $this->postJson('/auth/login', [
            'email' => 'user@example.com',
            'password' => 'Password123!',
        ]);

        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/json');
        $response->assertHeaderMissing('Set-Cookie');
        $response->assertHeaderMissing('X-Powered-By');

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
        $this->clearRateLimiter('auth-login', 'invalid@example.com');

        $user = User::factory()->create([
            'email' => 'invalid@example.com',
            'password_hash' => Hash::make('Password123!'),
        ]);

        $response = $this->postJson('/auth/login', [
            'email' => 'invalid@example.com',
            'password' => 'WrongPassword!',
        ]);

        $response->assertUnauthorized();
        $response->assertJsonPath('error.code', 'UNAUTHORIZED');

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $user->id,
            'action' => 'login_failed',
        ]);
    }

    public function test_login_requests_are_rate_limited_after_consecutive_failures(): void
    {
        $email = 'throttle@example.com';
        $this->clearRateLimiter('auth-login', $email);

        User::factory()->create([
            'email' => $email,
            'password_hash' => Hash::make('Password123!'),
        ]);

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->postJson('/auth/login', [
                'email' => $email,
                'password' => 'WrongPassword!',
            ])->assertStatus(401);
        }

        $this->postJson('/auth/login', [
            'email' => $email,
            'password' => 'WrongPassword!',
        ])->assertTooManyRequests();
    }

    public function test_refresh_issues_new_tokens_for_valid_refresh_token(): void
    {
        $this->clearRateLimiter('auth-login', 'refresh@example.com');

        $user = User::factory()->create([
            'tenant_id' => Tenant::factory()->create()->id,
            'email' => 'refresh@example.com',
            'password_hash' => Hash::make('Password123!'),
        ]);

        $loginResponse = $this->postJson('/auth/login', [
            'email' => 'refresh@example.com',
            'password' => 'Password123!',
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
        $this->clearRateLimiter('auth-login', 'logout@example.com');

        $tenant = Tenant::factory()->create();
        $role = Role::factory()->create([
            'code' => 'organizer',
            'tenant_id' => $tenant->id,
        ]);

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'logout@example.com',
            'password_hash' => Hash::make('Password123!'),
        ]);
        $user->roles()->attach($role->id, ['tenant_id' => $tenant->id]);

        $loginResponse = $this->postJson('/auth/login', [
            'email' => 'logout@example.com',
            'password' => 'Password123!',
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

    public function test_forgot_password_endpoint_is_rate_limited(): void
    {
        $email = 'forgot@example.com';
        $this->clearRateLimiter('auth-forgot', $email);

        User::factory()->create([
            'email' => $email,
            'password_hash' => Hash::make('Password123!'),
        ]);

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $this->postJson('/auth/forgot-password', [
                'email' => $email,
            ])->assertOk();
        }

        $this->postJson('/auth/forgot-password', [
            'email' => $email,
        ])->assertTooManyRequests();
    }

    private function clearRateLimiter(string $limiter, string $email, string $ip = '127.0.0.1'): void
    {
        RateLimiter::clear(sprintf('%s|%s|%s', $limiter, $ip, strtolower($email)));
    }
}
