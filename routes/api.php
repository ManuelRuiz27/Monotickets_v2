<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\RefreshTokenController;

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| These routes handle the authentication lifecycle using JWT tokens.
|
*/

Route::middleware('api')->group(function (): void {
    Route::post('/auth/login', [LoginController::class, 'login'])->name('auth.login');

    Route::middleware(['auth:api', 'role:superadmin,organizer,hostess'])->group(function (): void {
        Route::post('/auth/logout', [LogoutController::class, 'logout'])->name('auth.logout');
    });

    Route::post('/auth/refresh', [RefreshTokenController::class, 'refresh'])->name('auth.refresh');

    Route::post('/auth/forgot-password', [PasswordController::class, 'forgot'])->name('auth.forgot-password');
    Route::post('/auth/reset-password', [PasswordController::class, 'reset'])->name('auth.reset-password');
});
