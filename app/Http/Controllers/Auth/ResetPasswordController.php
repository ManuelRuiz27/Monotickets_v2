<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Handle password reset submissions and issue new JWT tokens.
 */
class ResetPasswordController extends Controller
{
    /**
     * Invoke the controller action.
     */
    public function __invoke(Request $request): JsonResponse
    {
        // TODO: Implement ResetPasswordController logic.
        return response()->json();
    }
}
