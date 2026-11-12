<?php
return [
    // Live quotes are fetched by the separately gated Navasan integration.
    'enabled'=>env('CNY_PRICING_ENABLED',false),
    'allow_sample'=>env('CNY_ALLOW_SAMPLE',false),
    // Independent fail-closed marker: keeps samples out of production even
    // if APP_ENV has accidentally been configured as local.
    'production_mode'=>env('CNY_PRODUCTION_MODE',true),
    'schedule_enabled'=>env('CNY_REPRICE_SCHEDULE_ENABLED',false),
    'max_rate_age_minutes'=>(int) env('CNY_MAX_RATE_AGE_MINUTES', 30),
];
