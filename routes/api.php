<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\RefreshTokenController;
use App\Http\Controllers\Auth\ForgotPasswordController;
use App\Http\Controllers\Auth\ResetPasswordController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| These routes handle the authentication lifecycle using JWT tokens.
|
*/

Route::middleware('api')->group(function (): void {
    Route::post('/auth/login', [LoginController::class, '__invoke'])->name('auth.login');

    Route::middleware(['auth:api', 'role:superadmin,organizer,hostess'])->group(function (): void {
        Route::post('/auth/logout', [LogoutController::class, '__invoke'])->name('auth.logout');
        Route::post('/auth/refresh', [RefreshTokenController::class, '__invoke'])->name('auth.refresh');
    });

    Route::post('/auth/forgot-password', [ForgotPasswordController::class, '__invoke'])->name('auth.forgot-password');
    Route::post('/auth/reset-password', [ResetPasswordController::class, '__invoke'])->name('auth.reset-password');
});
