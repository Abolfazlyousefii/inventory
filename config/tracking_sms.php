<?php

use App\Models\Invoice;
use App\Models\PreinvoiceOrder;

// Warehouse collection is one customer-facing phase, even when its internal
// Eloquent status changes from received to collecting, checking, or packing.
$warehouseProcessingStatuses = [
    Invoice::STATUS_WAREHOUSE_RECEIVED,
    Invoice::STATUS_COLLECTING,
    Invoice::STATUS_CHECKING_DISCREPANCY,
    Invoice::STATUS_FINAL_CHECK,
    Invoice::STATUS_PACKING,
];

return [
    // Enable customer notifications after confirming the SMS provider is configured.
    'enabled' => env('TRACKING_STATUS_SMS_ENABLED', true),
    'queue_connection' => env('TRACKING_STATUS_SMS_QUEUE_CONNECTION', 'database'),
    'queue' => 'tracking-sms',
    // Required: a separate approved Melipayamak template with TWO arguments (document, status).
    // Never reuse the OTP body ID here.
    'pattern_id' => env('TRACKING_STATUS_SMS_PATTERN_ID', '558023'),

    'warehouse_processing_statuses' => $warehouseProcessingStatuses,

    'important_statuses' => [
        'preinvoice' => [
            PreinvoiceOrder::STATUS_WAREHOUSE_REVIEWING,
            PreinvoiceOrder::STATUS_WAREHOUSE_APPROVED_WAITING_FINANCE,
            PreinvoiceOrder::STATUS_PENDING_FINANCE,
            PreinvoiceOrder::STATUS_RETURNED_TO_WAREHOUSE,
            PreinvoiceOrder::STATUS_RETURNED_TO_SALES,
            PreinvoiceOrder::STATUS_CANCELLED_BY_WAREHOUSE,
            PreinvoiceOrder::STATUS_CANCELLED_BY_FINANCE,
            PreinvoiceOrder::STATUS_RESERVATION_EXPIRED,
            PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE,
        ],
        'invoice' => [
            ...$warehouseProcessingStatuses,
            Invoice::STATUS_PENDING_FINANCE_REAPPROVAL,
            Invoice::STATUS_RETURNED_TO_SALES_AFTER_COLLECTION,
            Invoice::STATUS_READY_TO_SHIP,
            Invoice::STATUS_SHIPPED,
            Invoice::STATUS_NOT_SHIPPED,
        ],
    ],
];
