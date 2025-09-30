<?php

$defaultOrigins = implode(',', array_filter([
    env('APP_URL'),
    'https://app.monotickets.com',
    'https://admin.monotickets.com',
    'http://localhost',
    'http://localhost:3000',
    'http://localhost:5173',
    'http://localhost:5174',
]));

return [
    'paths' => ['api/*', 'auth/*'],

    'allowed_methods' => ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],

    'allowed_origins' => array_filter(array_map('trim', explode(',', env('CORS_ALLOWED_ORIGINS', $defaultOrigins)))),

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['Accept', 'Authorization', 'Content-Type', 'X-Requested-With', 'X-Tenant-ID', 'X-Device-ID'],

    'exposed_headers' => ['Authorization'],

    'max_age' => 3600,

    'supports_credentials' => false,
];
