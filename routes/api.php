<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\RefreshTokenController;
use App\Http\Middleware\EnsureTenantHeader;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| These routes handle the authentication lifecycle using JWT tokens.
|
*/

Route::middleware('api')->group(function (): void {
    Route::prefix('auth')
        ->withoutMiddleware([EnsureTenantHeader::class])
        ->group(function (): void {
            Route::post('login', [LoginController::class, 'login'])->name('auth.login');

            Route::middleware(['auth:api', 'role:superadmin,organizer,hostess'])->group(function (): void {
                Route::post('logout', [LogoutController::class, 'logout'])->name('auth.logout');
            });

            Route::post('refresh', [RefreshTokenController::class, 'refresh'])->name('auth.refresh');

            Route::post('forgot-password', [PasswordController::class, 'forgot'])->name('auth.forgot-password');
            Route::post('reset-password', [PasswordController::class, 'reset'])->name('auth.reset-password');
        });
});
