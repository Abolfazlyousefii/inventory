<?php

namespace App\Observers;

use App\Jobs\SendCustomerStatusSms;
use App\Models\Invoice;
use App\Models\PreinvoiceOrder;
use Illuminate\Database\Eloquent\Model;

final class OrderStatusSmsObserver
{
    public function updated(Model $document): void
    {
        if (! config('tracking_sms.enabled') || ! $document->wasChanged('status')) {
            return;
        }

        $type = match (true) {
            $document instanceof Invoice => 'invoice',
            $document instanceof PreinvoiceOrder => 'preinvoice',
            default => null,
        };

        $status = (string) $document->status;
        if ($type === null || ! in_array($status, config("tracking_sms.important_statuses.{$type}", []), true)) {
            return;
        }

        // Never send a customer-scoped link from a record whose owner was just reassigned.
        if ($document->wasChanged('customer_id') || (int) $document->customer_id < 1) {
            return;
        }

        // The queue entry is committed only after the status transaction commits.
        SendCustomerStatusSms::dispatch(
            $type,
            (int) $document->getKey(),
            (int) $document->customer_id,
            $status
        )->onConnection((string) config('tracking_sms.queue_connection', 'database'))
            ->onQueue((string) config('tracking_sms.queue', 'tracking-sms'))
            ->afterCommit();
    }
}
