<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\RefreshTokenController;
use App\Http\Controllers\CheckpointController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\GuestController;
use App\Http\Controllers\GuestListController;
use App\Http\Controllers\QrController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\ScanController;
use App\Http\Controllers\VenueController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\ImportController;
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
            Route::post('login', [LoginController::class, 'login'])
                ->middleware('throttle:auth-login')
                ->name('auth.login');

            Route::middleware(['auth:api', 'role:superadmin,organizer,hostess'])->group(function (): void {
                Route::post('logout', [LogoutController::class, 'logout'])->name('auth.logout');
            });

            Route::post('refresh', [RefreshTokenController::class, 'refresh'])->name('auth.refresh');

            Route::post('forgot-password', [PasswordController::class, 'forgot'])
                ->middleware('throttle:auth-forgot')
                ->name('auth.forgot-password');
            Route::post('reset-password', [PasswordController::class, 'reset'])->name('auth.reset-password');
        });

    Route::middleware(['auth:api', 'role:superadmin,organizer'])
        ->prefix('users')
        ->group(function (): void {
            Route::get('/', [UserController::class, 'index'])->name('users.index');
            Route::post('/', [UserController::class, 'store'])->name('users.store');
            Route::get('{user}', [UserController::class, 'show'])->name('users.show');
            Route::patch('{user}', [UserController::class, 'update'])->name('users.update');
            Route::delete('{user}', [UserController::class, 'destroy'])->name('users.destroy');
        });

    Route::middleware(['auth:api', 'role:superadmin,organizer'])
        ->prefix('events')
        ->group(function (): void {
            Route::get('/', [EventController::class, 'index'])->name('events.index');
            Route::post('/', [EventController::class, 'store'])->name('events.store');
            Route::get('{eventId}', [EventController::class, 'show'])->name('events.show');
            Route::patch('{eventId}', [EventController::class, 'update'])->name('events.update');
            Route::delete('{eventId}', [EventController::class, 'destroy'])->name('events.destroy');

            Route::get('{event_id}/guest-lists', [GuestListController::class, 'index'])->name('events.guest-lists.index');
            Route::post('{event_id}/guest-lists', [GuestListController::class, 'store'])->name('events.guest-lists.store');

            Route::get('{event_id}/guests', [GuestController::class, 'index'])->name('events.guests.index');
            Route::post('{event_id}/guests', [GuestController::class, 'store'])->name('events.guests.store');

            Route::post('{event_id}/imports', [ImportController::class, 'store'])->name('events.imports.store');

            Route::get('{eventId}/venues', [VenueController::class, 'index'])->name('events.venues.index');
            Route::post('{eventId}/venues', [VenueController::class, 'store'])->name('events.venues.store');
            Route::get('{eventId}/venues/{venueId}', [VenueController::class, 'show'])->name('events.venues.show');
            Route::patch('{eventId}/venues/{venueId}', [VenueController::class, 'update'])->name('events.venues.update');
            Route::delete('{eventId}/venues/{venueId}', [VenueController::class, 'destroy'])->name('events.venues.destroy');

            Route::get('{eventId}/venues/{venueId}/checkpoints', [CheckpointController::class, 'index'])
                ->name('events.venues.checkpoints.index');
            Route::post('{eventId}/venues/{venueId}/checkpoints', [CheckpointController::class, 'store'])
                ->name('events.venues.checkpoints.store');
            Route::get('{eventId}/venues/{venueId}/checkpoints/{checkpointId}', [CheckpointController::class, 'show'])
                ->name('events.venues.checkpoints.show');
            Route::patch('{eventId}/venues/{venueId}/checkpoints/{checkpointId}', [CheckpointController::class, 'update'])
                ->name('events.venues.checkpoints.update');
            Route::delete('{eventId}/venues/{venueId}/checkpoints/{checkpointId}', [CheckpointController::class, 'destroy'])
                ->name('events.venues.checkpoints.destroy');
        });

    Route::middleware(['auth:api', 'role:superadmin,organizer'])
        ->prefix('imports')
        ->group(function (): void {
            Route::get('{import_id}', [ImportController::class, 'show'])->name('imports.show');
            Route::get('{import_id}/rows', [ImportController::class, 'rows'])->name('imports.rows');
        });

    Route::middleware(['auth:api', 'role:superadmin,organizer'])
        ->prefix('guest-lists')
        ->group(function (): void {
            Route::get('{id}', [GuestListController::class, 'show'])->name('guest-lists.show');
            Route::patch('{id}', [GuestListController::class, 'update'])->name('guest-lists.update');
            Route::delete('{id}', [GuestListController::class, 'destroy'])->name('guest-lists.destroy');
        });

    Route::middleware(['auth:api', 'role:superadmin,organizer'])
        ->prefix('guests')
        ->group(function (): void {
            Route::get('{guest_id}', [GuestController::class, 'show'])->name('guests.show');
            Route::patch('{guest_id}', [GuestController::class, 'update'])->name('guests.update');
            Route::delete('{guest_id}', [GuestController::class, 'destroy'])->name('guests.destroy');
            Route::get('{guest_id}/tickets', [TicketController::class, 'index'])->name('guests.tickets.index');
            Route::post('{guest_id}/tickets', [TicketController::class, 'store'])->name('guests.tickets.store');
        });

    Route::middleware(['auth:api', 'role:superadmin,organizer'])
        ->prefix('tickets')
        ->group(function (): void {
            Route::get('{ticket_id}', [TicketController::class, 'show'])->name('tickets.show');
            Route::patch('{ticket_id}', [TicketController::class, 'update'])->name('tickets.update');
            Route::delete('{ticket_id}', [TicketController::class, 'destroy'])->name('tickets.destroy');
            Route::get('{ticket_id}/qr', [QrController::class, 'show'])->name('tickets.qr.show');
            Route::post('{ticket_id}/qr', [QrController::class, 'store'])->name('tickets.qr.store');
        });

    Route::middleware(['auth:api', 'role:superadmin,organizer,hostess'])->group(function (): void {
        Route::post('scan', [ScanController::class, 'store'])->name('scan.store');
        Route::post('scan/batch', [ScanController::class, 'batch'])->name('scan.batch');
    });
});
