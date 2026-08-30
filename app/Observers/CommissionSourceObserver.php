<?php

namespace App\Observers;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\Commissions\CommissionInvoiceSyncOutboxService;
use Illuminate\Database\Eloquent\Model;

class CommissionSourceObserver
{
    public function created(Model $model): void { $this->stage($model); }
    public function updated(Model $model): void { $this->stage($model); }
    public function deleted(Model $model): void { $this->stage($model); }

    private function stage(Model $model): void
    {
        $outbox = app(CommissionInvoiceSyncOutboxService::class);

        if ($model instanceof Invoice) {
            $outbox->stage(
                (int) $model->id,
                (string) $model->uuid,
                $model->getOriginal('document_date') ?: $model->getOriginal('created_at'),
                $model->display_document_date,
            );
            return;
        }

        if (! $model instanceof InvoiceItem) {
            return;
        }

        $invoiceIds = array_values(array_unique(array_filter([
            (int) ($model->getOriginal('invoice_id') ?: 0),
            (int) ($model->invoice_id ?: 0),
        ])));

        foreach ($invoiceIds as $invoiceId) {
            $invoice = Invoice::query()->find($invoiceId);
            if (! $invoice) {
                // Parent Invoice deletion has its own outbox row and immutable
                // invoice-number snapshot, which is the canonical cleanup path.
                continue;
            }

            $outbox->stage(
                (int) $invoice->id,
                (string) $invoice->uuid,
                $invoice->display_document_date,
                $invoice->display_document_date,
            );
        }
    }
}
