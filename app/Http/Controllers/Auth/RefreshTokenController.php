<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Refresh expired JWT access tokens using refresh tokens.
 */
class RefreshTokenController extends Controller
{
    /**
     * Invoke the controller action.
     */
    public function __invoke(Request $request): JsonResponse
    {
        // TODO: Implement RefreshTokenController logic.
        return response()->json();
    }
}
