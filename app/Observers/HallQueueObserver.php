<?php

namespace App\Observers;

use App\Models\Invoice;
use App\Models\PreinvoiceOrder;
use App\Services\Tracking\HallQueueRecorder;

final class HallQueueObserver
{
    public function __construct(private readonly HallQueueRecorder $recorder) {}

    public function created(Invoice|PreinvoiceOrder $model): void
    {
        $this->recorder->capture($model, null);
    }

    public function updated(Invoice|PreinvoiceOrder $model): void
    {
        if ($model->wasChanged('status')) {
            $this->recorder->capture($model, (string) $model->getRawOriginal('status'));
        }
    }

    public function deleted(Invoice|PreinvoiceOrder $model): void
    {
        $this->recorder->capture($model, (string) $model->status, 'deleted');
    }
}
