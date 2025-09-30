<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'name' => config('app.name', 'Monotickets'),
        'status' => 'ok',
        'timestamp' => now()->toIso8601String(),
        'documentation' => url('/docs'),
    ]);
});

if (app()->environment('local')) {
    Route::get('/docs', function () {
        return response()->view('docs.index', [
            'schemaUrl' => url('/docs/openapi.yaml'),
        ]);
    })->name('docs.index');

    Route::get('/docs/openapi.yaml', function () {
        $path = base_path('docs/api/openapi_monotickets.yaml');

        abort_unless(file_exists($path), 404);

        return response()->file($path, [
            'Content-Type' => 'application/yaml',
            'Cache-Control' => 'no-cache, no-store, must-revalidate',
        ]);
    })->name('docs.schema');
}
