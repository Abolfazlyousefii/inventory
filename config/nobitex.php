<?php

return [
    // Both sources are public, unauthenticated and used READ-ONLY.
    'enabled' => env('NOBITEX_ENABLED', false),
    'schedule_enabled' => env('NOBITEX_FETCH_SCHEDULE_ENABLED', false),
    'url' => 'https://apiv2.nobitex.ir/v3/orderbook/USDTIRT',
    'market' => 'USDTIRT',
    'fx_url' => 'https://api.frankfurter.dev/v2/rate/usd/cny',
    // The reference's DATE is the source timestamp. 120h accommodates weekends;
    // an old source fails closed rather than silently extending its freshness.
    'max_fx_age_hours' => (int) env('NOBITEX_FX_MAX_AGE_HOURS', 120),
    'max_quote_age_minutes' => (int) env('NOBITEX_MAX_QUOTE_AGE_MINUTES', 15),
    'max_spread_percent' => (int) env('NOBITEX_MAX_SPREAD_PERCENT', 4),
    'max_change_percent' => (int) env('NOBITEX_MAX_CHANGE_PERCENT', 15),
];
