<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RefreshTokenRequest;
use App\Models\Session;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Refresh expired JWT access tokens using refresh tokens.
 */
class RefreshTokenController extends Controller
{
    /**
     * Issue a new access and refresh token pair using a refresh token.
     */
    public function refresh(RefreshTokenRequest $request): JsonResponse
    {
        $token = $request->validated()['refresh_token'];

        try {
            $payload = JWTAuth::setToken($token)->getPayload();
        } catch (JWTException $exception) {
            return $this->unauthorizedResponse('Invalid refresh token.');
        }

        if ($payload->get('token_type') !== 'refresh') {
            return $this->unauthorizedResponse('Invalid token type.');
        }

        $userId = $payload->get('sub');
        $sessionId = $payload->get('sid');

        /** @var User|null $user */
        $user = User::query()->whereKey($userId)->first();

        if (! $user) {
            return $this->unauthorizedResponse('Unable to refresh the token.');
        }

        /** @var Session|null $session */
        $session = Session::query()->whereKey($sessionId)->where('user_id', $user->id)->first();

        if (! $session || $session->revoked_at || now()->greaterThanOrEqualTo($session->expires_at)) {
            return $this->unauthorizedResponse('Session is no longer valid.');
        }

        $factory = JWTAuth::factory();
        $originalTtl = $factory->getTTL();
        $accessTtl = (int) config('jwt.ttl');
        $refreshTtl = (int) config('jwt.refresh_ttl');

        try {
            JWTAuth::setToken($token)->invalidate();
        } catch (JWTException $exception) {
            return $this->unauthorizedResponse('Unable to invalidate refresh token.');
        }

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
            return $this->unauthorizedResponse('Unable to refresh authentication token.');
        } finally {
            $factory->setTTL($originalTtl);
        }

        $session->forceFill([
            'expires_at' => now()->addMinutes($refreshTtl),
        ])->save();

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
}
