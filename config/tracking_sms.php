<?php

use App\Models\Invoice;
use App\Models\PreinvoiceOrder;



return [
    'enabled' => env('TRACKING_STATUS_SMS_ENABLED', true),

    'pattern_id' => env('TRACKING_STATUS_SMS_PATTERN_ID', '558023'),


    // Customer order status SMS is sent only for these three customer-facing states.
        'allowed_statuses' => [
        'invoice' => [
            Invoice::STATUS_COLLECTING,
            Invoice::STATUS_SHIPPED,
            Invoice::STATUS_NOT_SHIPPED,
        ],
        'preinvoice' => [
                        PreinvoiceOrder::STATUS_CANCELLED_BY_WAREHOUSE,
                        PreinvoiceOrder::STATUS_CANCELLED_BY_FINANCE,
                   ],
    ],
];
