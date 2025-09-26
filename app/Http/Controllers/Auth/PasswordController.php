<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\ForgotPasswordRequest;
use App\Http\Requests\Auth\ResetPasswordRequest;
use App\Models\AuditLog;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Handle password reset lifecycle actions.
 */
class PasswordController extends Controller
{
    /**
     * Initiate a password reset for the supplied email address.
     */
    public function forgot(ForgotPasswordRequest $request): JsonResponse
    {
        $email = $request->validated()['email'];
        $token = Str::random(64);
        $hashedToken = Hash::make($token);

        DB::table('password_resets')->updateOrInsert(
            ['email' => $email],
            [
                'token' => $hashedToken,
                'created_at' => now(),
            ]
        );

        /** @var User|null $user */
        $user = User::where('email', $email)->first();

        if ($user) {
            AuditLog::create([
                'tenant_id' => $user->tenant_id,
                'user_id' => $user->id,
                'entity' => 'auth',
                'entity_id' => $user->id,
                'action' => 'password_forgot',
                'diff_json' => [
                    'email' => $email,
                ],
                'ip' => (string) $request->ip(),
                'ua' => (string) $request->userAgent(),
                'occurred_at' => CarbonImmutable::now(),
            ]);
        }

        Log::info('Password reset requested.', [
            'email' => $email,
            'token' => $token,
        ]);

        return response()->json([
            'message' => 'If the email exists, a reset link has been sent.',
        ]);
    }

    /**
     * Complete a password reset using a token.
     */
    public function reset(ResetPasswordRequest $request): JsonResponse
    {
        $data = $request->validated();

        $record = DB::table('password_resets')->where('email', $data['email'])->first();

        if (! $record || ! Hash::check($data['token'], $record->token)) {
            return $this->unauthorizedResponse('Invalid password reset token.');
        }

        /** @var User|null $user */
        $user = User::where('email', $data['email'])->first();

        if (! $user) {
            return $this->unauthorizedResponse('User account not found.');
        }

        $user->forceFill([
            'password_hash' => Hash::make($data['password']),
        ])->save();

        DB::table('password_resets')->where('email', $data['email'])->delete();

        AuditLog::create([
            'tenant_id' => $user->tenant_id,
            'user_id' => $user->id,
            'entity' => 'auth',
            'entity_id' => $user->id,
            'action' => 'password_reset',
            'diff_json' => [
                'email' => $user->email,
            ],
            'ip' => (string) $request->ip(),
            'ua' => (string) $request->userAgent(),
            'occurred_at' => CarbonImmutable::now(),
        ]);

        return response()->json([
            'message' => 'Password has been reset successfully.',
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
