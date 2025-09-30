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
