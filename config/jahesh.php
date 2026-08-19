<?php

return [
    'otp' => [
        'expires_minutes' => (int) env('CUSTOMER_OTP_EXPIRES_MINUTES', 5),
        'cooldown_seconds' => (int) env('CUSTOMER_OTP_COOLDOWN_SECONDS', 60),
    ],
];
