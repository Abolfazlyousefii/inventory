<?php

namespace App\Services\Tracking;

use App\Models\Invoice;
use App\Models\PreinvoiceOrder;

final class OrderTrackingStatus
{
    /** Match the Invoice list badge colors; status text remains the model's exact label. */
    /** @return array{0: string, 1: int, 2: string} */
    public static function invoice(string $status): array
    {
        [$step, $tone] = match ($status) {
            'pending_warehouse_approval' => [1, 'secondary'],
            'pending_finance_reapproval' => [1, 'warning'],
            'pending_collection', 'warehouse_received', 'collecting', 'checking_discrepancy' => [2, 'secondary'],
            'returned_to_sales_after_collection' => [2, 'operator-return'],
            'final_check', 'packing' => [3, 'secondary'],
            'ready_to_ship' => [3, 'info'],
            'shipped' => [4, 'success'],
            'not_shipped' => [0, 'cancelled'],
            default => [1, 'secondary'],
        };

        return [Invoice::statusLabels()[$status] ?? $status, $step, $tone];
    }

    /** Match the pre-invoice list's pending/approved/cancelled palette. */
    /** @return array{0: string, 1: int, 2: string} */
    public static function preinvoice(string $status): array
    {
        [$step, $tone] = match ($status) {
            'reserved_waiting_warehouse'       => [1, 'pending'],
            'warehouse_reviewing'              => [1, 'reviewing'],
            'warehouse_approved_waiting_finance'=> [1, 'warehouse-approved'],
            'finance_reviewing'                => [1, 'finance-review'],
            'pending_finance'                  => [2, 'finance-pending'],
            'returned_to_warehouse'            => [1, 'returned-warehouse'],
            'returned_to_sales'                => [1, 'returned-sales'],
            'converted_to_invoice'             => [2, 'approved'],
            'reservation_expired'              => [0, 'expired'],
            'cancelled_by_warehouse',
            'cancelled_by_finance'              => [0, 'cancelled'],
            default                            => [0, 'secondary'],
        };

        return [PreinvoiceOrder::statusLabels()[$status] ?? $status, $step, $tone];
    }

    /** Stable, anonymous public reference. Not a database ID or an access token. */
    public static function reference(string $type, int $id): string
    {
        $prefix = $type === 'invoice' ? 'F' : 'P';

        return $prefix . '-' . strtoupper(substr(hash_hmac(
                'sha256', $type . ':' . $id, (string) config('app.key')
            ), 0, 10));
    }
}
