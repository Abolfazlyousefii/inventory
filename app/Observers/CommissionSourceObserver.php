<?php

namespace App\Observers;

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\Commissions\CommissionPeriodDirtyMarker;
use Illuminate\Database\Eloquent\Model;

class CommissionSourceObserver
{
    public function created(Model $model): void
    {
        $this->mark($model);
    }

    public function updated(Model $model): void
    {
        $this->mark($model);
    }

    public function deleted(Model $model): void
    {
        $this->mark($model);
    }

    private function mark(Model $model): void
    {
        $marker = app(CommissionPeriodDirtyMarker::class);
        if ($model instanceof Invoice) {
            $marker->markInvoice($model);

            return;
        }
        if ($model instanceof InvoiceItem) {
            $marker->markInvoiceId((int) ($model->invoice_id ?: $model->getOriginal('invoice_id')));
        }
    }
}
