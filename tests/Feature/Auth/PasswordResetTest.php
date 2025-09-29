<?php

namespace Tests\Feature\Auth;

use App\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config(['tenant.id' => null]);
    }

    public function test_reset_password_fails_when_token_expired(): void
    {
        $tenant = Tenant::factory()->create();

        $user = User::factory()->create([
            'tenant_id' => $tenant->id,
            'email' => 'expired@example.com',
            'password_hash' => Hash::make('OriginalPass123!'),
        ]);

        $token = Str::random(64);

        DB::table('password_resets')->insert([
            'email' => $user->email,
            'token' => Hash::make($token),
            'created_at' => now()->subMinutes(config('auth.password_reset_expiration_minutes', 60) + 5),
        ]);

        $response = $this->postJson('/auth/reset-password', [
            'email' => $user->email,
            'token' => $token,
            'password' => 'NewPassword123!',
            'password_confirmation' => 'NewPassword123!',
        ]);

        $response->assertUnauthorized();
        $response->assertJsonPath('error.code', 'UNAUTHORIZED');
        $response->assertJsonPath('error.message', 'Password reset token has expired.');

        $this->assertDatabaseMissing('password_resets', ['email' => $user->email]);
    }
}
