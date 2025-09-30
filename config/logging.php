<?php

use Monolog\Formatter\JsonFormatter;

return [
    'default' => env('LOG_CHANNEL', 'stack'),

    'channels' => [
        'stack' => [
            'driver' => 'stack',
            'channels' => ['daily'],
            'ignore_exceptions' => false,
        ],

        'daily' => [
            'driver' => 'daily',
            'path' => storage_path('logs/laravel.log'),
            'level' => env('LOG_LEVEL', 'debug'),
            'days' => 14,
            'tap' => [
                App\Logging\RequestContextTap::class,
            ],
            'formatter' => JsonFormatter::class,
            'formatter_with' => [
                'batchMode' => JsonFormatter::BATCH_MODE_NEWLINES,
                'appendNewline' => true,
            ],
        ],
    ],
];
