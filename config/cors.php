<?php

return [
    'paths' => ['api/*'],

    'allowed_methods' => ['*'],

    'allowed_origins' => [env('APP_URL', 'http://localhost:*'), 'https://*.monotickets.com'],

    'allowed_origins_patterns' => [],

    'allowed_headers' => ['*'],

    'exposed_headers' => ['Authorization', 'X-Requested-With'],

    'max_age' => 3600,

    'supports_credentials' => true,
];
