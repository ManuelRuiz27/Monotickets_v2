<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Handle the initiation of password reset via email.
 */
class ForgotPasswordController extends Controller
{
    /**
     * Invoke the controller action.
     */
    public function __invoke(Request $request): JsonResponse
    {
        // TODO: Implement ForgotPasswordController logic.
        return response()->json();
    }
}
