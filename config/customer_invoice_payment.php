<?php

// Configure recipient details privately in the hosting .env; do not commit personal
// identification or bank numbers to the public source repository.
return [
    'name' => env('CUSTOMER_INVOICE_PAYMENT_NAME', ''),
    'national_id' => env('CUSTOMER_INVOICE_PAYMENT_NATIONAL_ID', ''),
    'iban' => env('CUSTOMER_INVOICE_PAYMENT_IBAN', ''),
    'account' => env('CUSTOMER_INVOICE_PAYMENT_ACCOUNT', ''),
    'card' => env('CUSTOMER_INVOICE_PAYMENT_CARD', ''),
];
