<?php
return [
 'enabled'=>(bool) env('ARIYA_SITE_INTEGRATION_ENABLED', false),
 'base_url'=>env('ARIYA_SITE_BASE_URL'),
 'shared_secret'=>env('ARIYA_SITE_SHARED_SECRET'),
 'connect_timeout'=>(int) env('ARIYA_SITE_CONNECT_TIMEOUT',5),
 'timeout'=>(int) env('ARIYA_SITE_TIMEOUT',15),
 'verify_ssl'=>(bool) env('ARIYA_SITE_VERIFY_SSL',true),
 'max_attempts'=>(int) env('ARIYA_SITE_MAX_ATTEMPTS',10),
 'allowed_ips'=>array_values(array_filter(array_map('trim', explode(',', (string) env('ARIYA_SITE_ALLOWED_IPS',''))))),
 'queue'=>env('ARIYA_SITE_QUEUE','integrations'),
 'backoff'=>array_map('intval', array_filter(explode(',', env('ARIYA_SITE_BACKOFF_SECONDS','10,30,120,300,900,3600,10800,21600,43200')))),
];
