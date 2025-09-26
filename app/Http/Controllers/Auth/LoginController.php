<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Handle login requests and issue JWT tokens.
 */
class LoginController extends Controller
{
    /**
     * Invoke the controller action.
     */
    public function __invoke(Request $request): JsonResponse
    {
        // TODO: Implement LoginController logic.
        return response()->json();
    }
}
