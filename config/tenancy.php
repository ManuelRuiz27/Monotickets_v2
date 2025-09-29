<?php

return [
    'limit_warning_threshold' => (float) env('TENANCY_LIMIT_WARNING_THRESHOLD', 0.9),
    'anonymize_canceled_after_days' => (int) env('TENANCY_ANONYMIZE_AFTER_DAYS', 30),
];
