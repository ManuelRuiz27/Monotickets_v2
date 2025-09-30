<?php

use Illuminate\Support\Facades\Route;
use App\Http\Controllers\AdminAnalyticsController;
use App\Http\Controllers\AdminPlanController;
use App\Http\Controllers\AdminTenantController;
use App\Http\Controllers\Auth\LoginController;
use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\Auth\PasswordController;
use App\Http\Controllers\Auth\RefreshTokenController;
use App\Http\Controllers\Billing\BillingController;
use App\Http\Controllers\CheckpointController;
use App\Http\Controllers\DeviceController;
use App\Http\Controllers\EventAttendanceController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\EventDashboardController;
use App\Http\Controllers\EventReportController;
use App\Http\Controllers\EventStreamController;
use App\Http\Controllers\GuestController;
use App\Http\Controllers\GuestListController;
use App\Http\Controllers\HostessAssignmentController;
use App\Http\Controllers\HostessAssignmentMeController;
use App\Http\Controllers\ImportController;
use App\Http\Controllers\QrController;
use App\Http\Controllers\ScanController;
use App\Http\Controllers\TenantBrandingController;
use App\Http\Controllers\TenantOverviewController;
use App\Http\Controllers\TicketController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\VenueController;
use App\Http\Middleware\ResolveTenant;

Route::get('/', function () {
    return response()->json([
        'name' => config('app.name', 'Monotickets'),
        'version' => 'v1',
        'status' => 'ok',
        'timestamp' => now()->toIso8601String(),
    ]);
});

/*
|--------------------------------------------------------------------------
| API Routes
|--------------------------------------------------------------------------
|
| These routes handle the authentication lifecycle using JWT tokens.
|
*/

Route::middleware('api')->group(function (): void {
    Route::options('{any}', function () {
        return response()->noContent();
    })->where('any', '.*');

    Route::options('auth/{any}', function () {
        return response()->noContent();
    })->where('any', '.*');

    Route::prefix('auth')
        ->withoutMiddleware([ResolveTenant::class])
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

    Route::middleware(['auth:api', 'role:superadmin'])
        ->prefix('admin')
        ->group(function (): void {
            Route::get('analytics', [AdminAnalyticsController::class, 'index'])->name('admin.analytics.index');
            Route::get('plans', [AdminPlanController::class, 'index'])->name('admin.plans.index');
            Route::get('tenants', [AdminTenantController::class, 'index'])->name('admin.tenants.index');
            Route::post('tenants', [AdminTenantController::class, 'store'])->name('admin.tenants.store');
            Route::patch('tenants/{tenant}', [AdminTenantController::class, 'update'])->name('admin.tenants.update');
            Route::get('tenants/{tenant}/usage', [AdminTenantController::class, 'usage'])->name('admin.tenants.usage');
        });

    Route::middleware(['auth:api', 'role:superadmin,organizer'])
        ->prefix('users')
        ->group(function (): void {
            Route::get('/', [UserController::class, 'index'])->name('users.index');
            Route::post('/', [UserController::class, 'store'])
                ->middleware('limits:user.create')
                ->name('users.store');
            Route::get('{user}', [UserController::class, 'show'])->name('users.show');
            Route::patch('{user}', [UserController::class, 'update'])->name('users.update');
            Route::delete('{user}', [UserController::class, 'destroy'])->name('users.destroy');
        });

    Route::middleware(['auth:api', 'role:superadmin,organizer'])
        ->prefix('tenants')
        ->group(function (): void {
            Route::get('me/overview', [TenantOverviewController::class, 'show'])->name('tenants.me.overview');
            Route::get('me/branding', [TenantBrandingController::class, 'show'])->name('tenants.me.branding.show');
            Route::patch('me/branding', [TenantBrandingController::class, 'update'])->name('tenants.me.branding.update');
        });

    Route::middleware(['auth:api', 'role:superadmin,organizer'])
        ->prefix('events')
        ->group(function (): void {
            Route::get('/', [EventController::class, 'index'])->name('events.index');
            Route::post('/', [EventController::class, 'store'])
                ->middleware('limits:event.create')
                ->name('events.store');
            Route::get('{event_id}', [EventController::class, 'show'])->name('events.show');
            Route::patch('{event_id}', [EventController::class, 'update'])->name('events.update');
            Route::delete('{event_id}', [EventController::class, 'destroy'])->name('events.destroy');

            Route::get('{event_id}/guest-lists', [GuestListController::class, 'index'])->name('events.guest-lists.index');
            Route::post('{event_id}/guest-lists', [GuestListController::class, 'store'])->name('events.guest-lists.store');

            Route::get('{event_id}/guests', [GuestController::class, 'index'])->name('events.guests.index');
            Route::post('{event_id}/guests', [GuestController::class, 'store'])->name('events.guests.store');

            Route::prefix('{event_id}/dashboard')->middleware('cache.dashboard')->group(function (): void {
                Route::get('overview', [EventDashboardController::class, 'overview'])->name('events.dashboard.overview');
                Route::get('attendance-by-hour', [EventDashboardController::class, 'attendanceByHour'])->name('events.dashboard.attendance-by-hour');
                Route::get('checkpoint-totals', [EventDashboardController::class, 'checkpointTotals'])->name('events.dashboard.checkpoint-totals');
                Route::get('rsvp-funnel', [EventDashboardController::class, 'rsvpFunnel'])->name('events.dashboard.rsvp-funnel');
                Route::get('guests-by-list', [EventDashboardController::class, 'guestsByList'])->name('events.dashboard.guests-by-list');
            });

            Route::prefix('{event_id}/reports')->group(function (): void {
                Route::get('attendance.csv', [EventReportController::class, 'attendanceCsv'])
                    ->middleware(['throttle:reports-export', 'limits:export,csv'])
                    ->name('events.reports.attendance');
                Route::get('summary.pdf', [EventReportController::class, 'summaryPdf'])
                    ->middleware(['throttle:reports-export', 'limits:export,pdf'])
                    ->name('events.reports.summary');
            });

            Route::post('{event_id}/imports', [ImportController::class, 'store'])->name('events.imports.store');

            Route::get('{event_id}/venues', [VenueController::class, 'index'])->name('events.venues.index');
            Route::post('{event_id}/venues', [VenueController::class, 'store'])->name('events.venues.store');
            Route::get('{event_id}/venues/{venue_id}', [VenueController::class, 'show'])->name('events.venues.show');
            Route::patch('{event_id}/venues/{venue_id}', [VenueController::class, 'update'])->name('events.venues.update');
            Route::delete('{event_id}/venues/{venue_id}', [VenueController::class, 'destroy'])->name('events.venues.destroy');

            Route::get('{event_id}/venues/{venue_id}/checkpoints', [CheckpointController::class, 'index'])
                ->name('events.venues.checkpoints.index');
            Route::post('{event_id}/venues/{venue_id}/checkpoints', [CheckpointController::class, 'store'])
                ->name('events.venues.checkpoints.store');
            Route::get('{event_id}/venues/{venue_id}/checkpoints/{checkpoint_id}', [CheckpointController::class, 'show'])
                ->name('events.venues.checkpoints.show');
            Route::patch('{event_id}/venues/{venue_id}/checkpoints/{checkpoint_id}', [CheckpointController::class, 'update'])
                ->name('events.venues.checkpoints.update');
            Route::delete('{event_id}/venues/{venue_id}/checkpoints/{checkpoint_id}', [CheckpointController::class, 'destroy'])
                ->name('events.venues.checkpoints.destroy');
        });

    Route::middleware(['auth:api', 'role:superadmin,organizer,hostess'])
        ->prefix('events')
        ->group(function (): void {
            Route::get('{event_id}/stream', [EventStreamController::class, 'stream'])
                ->name('events.stream');
            Route::get('{event_id}/attendances/since', [EventAttendanceController::class, 'attendancesSince'])
                ->name('events.attendances.since');
            Route::get('{event_id}/tickets/{ticket_id}/state', [EventAttendanceController::class, 'ticketState'])
                ->name('events.tickets.state');
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
            Route::get('{guest_list_id}', [GuestListController::class, 'show'])->name('guest-lists.show');
            Route::patch('{guest_list_id}', [GuestListController::class, 'update'])->name('guest-lists.update');
            Route::delete('{guest_list_id}', [GuestListController::class, 'destroy'])->name('guest-lists.destroy');
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
        ->prefix('hostess-assignments')
        ->group(function (): void {
            Route::get('/', [HostessAssignmentController::class, 'index'])->name('hostess-assignments.index');
            Route::post('/', [HostessAssignmentController::class, 'store'])->name('hostess-assignments.store');
            Route::get('{assignment_id}', [HostessAssignmentController::class, 'show'])->name('hostess-assignments.show');
            Route::patch('{assignment_id}', [HostessAssignmentController::class, 'update'])->name('hostess-assignments.update');
            Route::delete('{assignment_id}', [HostessAssignmentController::class, 'destroy'])->name('hostess-assignments.destroy');
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
        Route::post('devices/register', [DeviceController::class, 'register'])->name('devices.register');
        Route::get('me/assignments', [HostessAssignmentMeController::class, 'index'])->name('me.assignments.index');
        Route::post('scan', [ScanController::class, 'store'])
            ->middleware(['throttle:scan-device', 'limits:scan.record'])
            ->name('scan.store');
        Route::post('scan/batch', [ScanController::class, 'batch'])->name('scan.batch');
    });

    Route::middleware(['auth:api', 'role:superadmin,tenant_owner'])
        ->prefix('billing')
        ->group(function (): void {
            Route::get('invoices', [BillingController::class, 'index'])->name('billing.invoices.index');
            Route::get('invoices/{invoice_id}', [BillingController::class, 'show'])->name('billing.invoices.show');
            Route::get('invoices/{invoice_id}/pdf', [BillingController::class, 'downloadPdf'])
                ->middleware(['throttle:reports-export', 'limits:export,pdf'])
                ->name('billing.invoices.pdf');
            Route::post('preview', [BillingController::class, 'preview'])->name('billing.preview');
            Route::patch('invoices/close', [BillingController::class, 'close'])->name('billing.invoices.close');
            Route::patch('invoices/{invoice_id}/pay', [BillingController::class, 'pay'])->name('billing.invoices.pay');
        });
});
