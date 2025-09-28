<?php

return [
    'idempotency_window_seconds' => env('SCAN_IDEMPOTENCY_WINDOW_SECONDS', 5),
    'duplicate_grace_seconds' => env('SCAN_DUPLICATE_GRACE_SECONDS', 10),
];
