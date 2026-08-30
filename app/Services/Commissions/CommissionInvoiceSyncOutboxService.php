<?php

namespace App\Services\Commissions;

use App\Models\CommissionInvoiceSyncOutbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class CommissionInvoiceSyncOutboxService
{
    public function __construct(
        private readonly CommissionInvoiceSyncService $sync,
        private readonly CommissionPeriodDirtyMarker $dirtyMarker,
    ) {}

    /**
     * Stage work inside the source transaction, then attempt immediate delivery
     * after the outermost commit. Multiple source events can create multiple
     * cheap rows; the first callback drains all rows for the invoice at once.
     */
    public function stage(
        int $invoiceId,
        ?string $invoiceNumberSnapshot,
        mixed $oldDate,
        mixed $newDate,
    ): void {
        CommissionInvoiceSyncOutbox::query()->create([
            'invoice_id' => $invoiceId,
            'invoice_number_snapshot' => $invoiceNumberSnapshot,
            'old_date' => $oldDate,
            'new_date' => $newDate,
            'attempts' => 0,
            'last_error' => null,
            'available_at' => now(),
        ]);

        DB::afterCommit(function () use ($invoiceId): void {
            try {
                $this->processInvoice($invoiceId);
            } catch (\Throwable $exception) {
                report($exception);
            }
        });
    }

    public function processInvoice(int $invoiceId): bool
    {
        $due = CommissionInvoiceSyncOutbox::query()
            ->where('invoice_id', $invoiceId)
            ->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()))
            ->exists();

        if (! $due) {
            return false;
        }

        try {
            return DB::transaction(function () use ($invoiceId): bool {
                $rows = CommissionInvoiceSyncOutbox::query()
                    ->where('invoice_id', $invoiceId)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if ($rows->isEmpty()) {
                    return false;
                }

                $first = $rows->first();
                $last = $rows->last();
                $number = $rows->pluck('invoice_number_snapshot')->filter()->last();
                $oldDate = $rows->pluck('old_date')->filter()->first();
                $newDate = $rows->pluck('new_date')->filter()->last();

                $this->sync->syncInvoice(
                    $invoiceId,
                    $oldDate,
                    $newDate,
                    $number,
                );

                CommissionInvoiceSyncOutbox::query()->whereIn('id', $rows->pluck('id'))->delete();

                return true;
            }, 3);
        } catch (\Throwable $exception) {
            $this->recordFailure($invoiceId, $exception);
            throw $exception;
        }
    }

    public function drain(int $limit = 100): array
    {
        $invoiceIds = CommissionInvoiceSyncOutbox::query()
            ->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()))
            ->orderBy('id')
            ->limit(max(1, $limit * 5))
            ->pluck('invoice_id')
            ->unique()
            ->take($limit)
            ->values();

        $result = ['attempted' => 0, 'processed' => 0, 'failed' => 0];

        foreach ($invoiceIds as $invoiceId) {
            $result['attempted']++;
            try {
                if ($this->processInvoice((int) $invoiceId)) {
                    $result['processed']++;
                }
            } catch (\Throwable $exception) {
                $result['failed']++;
                report($exception);
            }
        }

        return $result;
    }

    private function recordFailure(int $invoiceId, \Throwable $exception): void
    {
        $rows = CommissionInvoiceSyncOutbox::query()
            ->where('invoice_id', $invoiceId)
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        $attempt = ((int) $rows->max('attempts')) + 1;
        $delaySeconds = min(300, 5 * (2 ** min(max($attempt - 1, 0), 6)));

        CommissionInvoiceSyncOutbox::query()->where('invoice_id', $invoiceId)->update([
            'attempts' => $attempt,
            'last_error' => mb_substr($exception->getMessage(), 0, 4000),
            'available_at' => now()->addSeconds($delaySeconds),
            'updated_at' => now(),
        ]);

        foreach ($rows->pluck('old_date')->merge($rows->pluck('new_date'))->filter() as $date) {
            $this->dirtyMarker->markDate($date);
        }
        $this->dirtyMarker->markInvoiceId($invoiceId);

        Log::error('Incremental commission outbox delivery failed.', [
            'invoice_id' => $invoiceId,
            'attempt' => $attempt,
            'retry_in_seconds' => $delaySeconds,
            'exception' => $exception->getMessage(),
        ]);
    }
}
