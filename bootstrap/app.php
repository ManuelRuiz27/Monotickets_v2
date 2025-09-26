<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__ . '/../routes/api.php',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Register middleware customisations if required.
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Configure exception handling as needed.
    })
    ->create();

