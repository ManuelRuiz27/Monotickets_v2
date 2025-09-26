<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Session;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Tymon\JWTAuth\Exceptions\JWTException;
use Tymon\JWTAuth\Facades\JWTAuth;

/**
 * Handle API logout requests by invalidating JWT tokens.
 */
class LogoutController extends Controller
{
    /**
     * Invalidate the current JWT token and revoke the active session.
     */
    public function logout(Request $request): JsonResponse
    {
        try {
            $token = JWTAuth::parseToken()->getToken();
            $payload = JWTAuth::setToken($token)->getPayload();
        } catch (JWTException $exception) {
            return $this->unauthorizedResponse('Invalid token provided.');
        }

        if ($payload->get('token_type') !== 'access') {
            return $this->unauthorizedResponse('Invalid token type.');
        }

        $sessionId = $payload->get('sid');

        if ($sessionId) {
            /** @var Session|null $session */
            $session = Session::query()
                ->whereKey($sessionId)
                ->when($request->user(), function ($query) use ($request) {
                    return $query->where('user_id', $request->user()->id);
                })
                ->first();

            if ($session && is_null($session->revoked_at)) {
                $session->forceFill(['revoked_at' => now()])->save();
            }
        }

        try {
            JWTAuth::setToken($token)->invalidate();
        } catch (JWTException $exception) {
            return $this->unauthorizedResponse('Unable to invalidate the token.');
        }

        if ($request->user()) {
            AuditLog::create([
                'tenant_id' => $request->user()->tenant_id,
                'user_id' => $request->user()->id,
                'entity' => 'auth',
                'entity_id' => $sessionId,
                'action' => 'logout',
                'diff_json' => [
                    'session_id' => $sessionId,
                ],
                'ip' => (string) $request->ip(),
                'ua' => (string) $request->userAgent(),
                'occurred_at' => CarbonImmutable::now(),
            ]);
        }

        return response()->json([
            'message' => 'Logged out successfully.',
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
