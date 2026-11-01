<?php

return [
    'access_key' => env('HALL_MANAGEMENT_ACCESS_KEY', env('HALL_DISPLAY_ACCESS_KEY', '')),
    'refresh_seconds' => 15,
    'cookie_hours' => 12,
    'draft_hours' => 24,
    'warning_minutes' => ['draft' => 30, 'finance' => 45, 'warehouse' => 60, 'shipping' => 90],
];
