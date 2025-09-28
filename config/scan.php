<?php

return [
    'idempotency_window_seconds' => env('SCAN_IDEMPOTENCY_WINDOW_SECONDS', 5),
    'duplicate_grace_seconds' => env('SCAN_DUPLICATE_GRACE_SECONDS', 10),
    'database_timeout_ms' => env('SCAN_DATABASE_TIMEOUT_MS', 250),
    'device_rate_limit_per_second' => env('SCAN_DEVICE_RATE_LIMIT_PER_SECOND', 10),
];
