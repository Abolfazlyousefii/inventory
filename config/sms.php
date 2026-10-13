<?php

return [
        // Preserve OTP_SMS_DRIVER; SMS_DRIVER can override it for every SMS operation.
        'default' => env('SMS_DRIVER', env('OTP_SMS_DRIVER', 'log')),
    ];