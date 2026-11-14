<?php

return [
    // Nothing is fetched until an operator explicitly activates this configuration.
    'enabled' => env('NAVASAN_ENABLED', true),
    'schedule_enabled' => env('NAVASAN_FETCH_SCHEDULE_ENABLED', false),
    'api_key' => env('NAVASAN_API_KEY', 'freekkxEQU1aXweTEDPJ7Iq3khSPVUus'),
    'url' => env('NAVASAN_API_URL', 'https://api.navasan.tech/latest/'),
    'symbol' => 'cny',
    // Fail closed until a real keyed response proves the provider's unit.
    'response_unit' => env('NAVASAN_RESPONSE_UNIT', 'unverified'),
    'max_change_percent' => (int) env('NAVASAN_MAX_CHANGE_PERCENT', 15),
    'monthly_rolling_limit' => (int) env('NAVASAN_ROLLING_31_DAY_LIMIT', 90),
    'max_quote_age_minutes' => (int) env('NAVASAN_MAX_QUOTE_AGE_MINUTES', 660),
];
