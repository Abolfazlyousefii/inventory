<?php
return [
    // The first local version uses explicitly entered sample rates. No external API call is made.
    'enabled'=>env('CNY_PRICING_ENABLED',false),
    'allow_sample'=>env('CNY_ALLOW_SAMPLE',false),
    'schedule_enabled'=>env('CNY_REPRICE_SCHEDULE_ENABLED',false),
    'max_rate_age_minutes'=>30,
];
