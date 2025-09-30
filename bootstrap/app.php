<?php

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__ . '/../routes/web.php',
        api: __DIR__ . '/../routes/api.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Register middleware customisations if required.
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Configure exception handling as needed.
    })
    ->withCommands([
        \App\Console\Commands\CloseBillingPeriodsCommand::class,
        \App\Console\Commands\AnonymizeCanceledTenantsCommand::class,
    ])
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('billing:close-periods')->monthlyOn(1, '02:00')->withoutOverlapping();
        $schedule->command('tenants:anonymize-canceled')->dailyAt('03:00');
    })
    ->create();
