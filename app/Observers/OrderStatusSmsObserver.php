<?php

namespace App\Observers;

use App\Events\CustomerOrderStatusChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use App\Models\Invoice;
use App\Models\PreinvoiceOrder;
use Illuminate\Database\Eloquent\Model;

class OrderStatusSmsObserver
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
        if ($type === null || ! in_array($status, config("tracking_sms.allowed_statuses.{$type}", []), true)) {
            return;
        }

        // Avoid notifying the wrong recipient if this update also reassigned the customer.
        if ($document->wasChanged('customer_id') || (int) $document->customer_id < 1) {
            return;
        }

        // Run synchronously after commit; no database queue, worker or cron.
        // Laravel executes this immediately when there is no open transaction.
        $id = (int) $document->getKey();
        $customerId = (int) $document->customer_id;
        DB::afterCommit(static function () use ($type, $id, $customerId, $status): void {
            Event::dispatch(new CustomerOrderStatusChanged($type, $id, $customerId, $status));
        });
    }
}
