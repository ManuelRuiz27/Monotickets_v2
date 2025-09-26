<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Handle API logout requests by invalidating JWT tokens.
 */
class LogoutController extends Controller
{
    /**
     * Invoke the controller action.
     */
    public function __invoke(Request $request): JsonResponse
    {
        // TODO: Implement LogoutController logic.
        return response()->json();
    }
}
