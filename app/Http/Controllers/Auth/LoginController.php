<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Models\AuditLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Hash;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Handle login requests and issue JWT tokens.
 */
class LoginController extends Controller
{
    /**
     * Authenticate the user and issue JWT access and refresh tokens.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        $credentials = $request->validated();

        /** @var User|null $user */
        $user = User::where('email', $credentials['email'])->first();

        if (! $user || ! $user->is_active || ! Hash::check($credentials['password'], (string) $user->password_hash)) {
            $this->logFailedAttempt($request, $user, $credentials['email']);

            return $this->unauthorizedResponse('Invalid email or password.');
        }

        $factory = JWTAuth::factory();
        $originalTtl = $factory->getTTL();
        $accessTtl = (int) config('jwt.ttl');
        $refreshTtl = (int) config('jwt.refresh_ttl');

        $session = $user->sessions()->create([
            'user_agent' => (string) $request->userAgent(),
            'ip' => (string) $request->ip(),
            'expires_at' => now()->addMinutes($refreshTtl),
        ]);

        try {
            $factory->setTTL($accessTtl);
            $accessToken = JWTAuth::claims([
                'token_type' => 'access',
                'sid' => $session->id,
            ])->fromUser($user);

            $factory->setTTL($refreshTtl);
            $refreshToken = JWTAuth::claims([
                'token_type' => 'refresh',
                'sid' => $session->id,
            ])->fromUser($user);
        } catch (JWTException $exception) {
            $session->delete();

            return $this->unauthorizedResponse('Unable to issue authentication token.');
        } finally {
            $factory->setTTL($originalTtl);
        }

        $user->forceFill(['last_login_at' => now()])->save();

        AuditLog::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'entity' => 'auth',
            'entity_id' => $session->id,
            'action' => 'login',
            'diff_json' => [
                'session_id' => $session->id,
            ],
            'ip' => (string) $request->ip(),
            'ua' => (string) $request->userAgent(),
            'occurred_at' => CarbonImmutable::now(),
        ]);

        return response()->json([
            'access_token' => $accessToken,
            'token_type' => 'Bearer',
            'expires_in' => $accessTtl * 60,
            'refresh_token' => $refreshToken,
            'refresh_expires_in' => $refreshTtl * 60,
            'session_id' => $session->id,
        ]);
    }

    /**
     * Generate a consistent unauthorized JSON response.
     */
    private function unauthorizedResponse(string $message): JsonResponse
    {
        return response()->json([
            'error' => [
                'code' => 'UNAUTHORIZED',
                'message' => $message,
            ],
        ], 401);
    }

    /**
     * Persist an audit trail for failed authentication attempts.
     */
    private function logFailedAttempt(LoginRequest $request, ?User $user, string $email): void
    {
        $reason = match (true) {
            $user === null => 'user_not_found',
            $user !== null && ! $user->is_active => 'user_inactive',
            default => 'invalid_password',
        };

        AuditLog::create([
            'tenant_id' => $user?->tenant_id,
            'user_id' => $user?->id,
            'entity' => 'auth',
            'entity_id' => $user?->id ?? $email,
            'action' => 'login_failed',
            'diff_json' => [
                'email' => $email,
                'reason' => $reason,
            ],
            'ip' => (string) $request->ip(),
            'ua' => (string) $request->userAgent(),
            'occurred_at' => CarbonImmutable::now(),
        ]);
    }
}
