<?php

namespace App\Services\Commissions;

use App\Models\CommissionReconciliationWarning;
use App\Models\CommissionReturnSyncOutbox;
use App\Models\SalesReturnDocument;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

class CommissionReturnSyncOutboxService
{
    public function __construct(
        private readonly CommissionReconciliationService $reconciliation,
        private readonly CommissionPeriodDirtyMarker $dirtyMarker,
    ) {}

    /**
     * Persist return-commission work in the same business transaction and
     * deliver only after commit. This keeps stock/customer-ledger finalization
     * independent from a transient commission failure.
     */
    public function stage(int $returnId, ?int $actorId = null): void
    {
        if (! Schema::hasTable('commission_return_sync_outboxes')) {
            // Deployment fallback: do not make the source transaction depend on
            // commission code while the migration is rolling out.
            DB::afterCommit(function () use ($returnId, $actorId): void {
                try {
                    $return = SalesReturnDocument::query()->find($returnId);
                    if ($return) {
                        $this->reconciliation->reconcileReturn($return, $actorId);
                    }
                } catch (\Throwable $exception) {
                    report($exception);
                }
            });

            return;
        }

        CommissionReturnSyncOutbox::query()->create([
            'sales_return_document_id' => $returnId,
            'actor_id' => $actorId,
            'attempts' => 0,
            'last_error' => null,
            'available_at' => now(),
        ]);

        DB::afterCommit(function () use ($returnId): void {
            try {
                $this->processReturn($returnId);
            } catch (\Throwable $exception) {
                // processReturn records retry metadata and a durable warning.
                report($exception);
            }
        });
    }

    public function processReturn(int $returnId): bool
    {
        if (! Schema::hasTable('commission_return_sync_outboxes')) {
            return false;
        }

        $due = CommissionReturnSyncOutbox::query()
            ->where('sales_return_document_id', $returnId)
            ->where(fn ($query) => $query
                ->whereNull('available_at')
                ->orWhere('available_at', '<=', now()))
            ->exists();

        if (! $due) {
            return false;
        }

        try {
            return DB::transaction(function () use ($returnId): bool {
                $rows = CommissionReturnSyncOutbox::query()
                    ->where('sales_return_document_id', $returnId)
                    ->orderBy('id')
                    ->lockForUpdate()
                    ->get();

                if ($rows->isEmpty()) {
                    return false;
                }

                $return = SalesReturnDocument::query()->find($returnId);
                if (! $return) {
                    $this->sourceMissingWarning($returnId);
                    CommissionReturnSyncOutbox::query()
                        ->whereIn('id', $rows->pluck('id')->all())
                        ->delete();

                    return true;
                }

                $actorId = $rows->pluck('actor_id')->filter()->last();
                $this->reconciliation->reconcileReturn(
                    $return,
                    $actorId ? (int) $actorId : null,
                );

                CommissionReturnSyncOutbox::query()
                    ->whereIn('id', $rows->pluck('id')->all())
                    ->delete();

                CommissionReconciliationWarning::query()
                    ->where('identity_key', 'return-sync-failed:'.$returnId)
                    ->whereNull('resolved_at')
                    ->update(['resolved_at' => now(), 'updated_at' => now()]);

                return true;
            }, 3);
        } catch (\Throwable $exception) {
            $this->recordFailure($returnId, $exception);
            throw $exception;
        }
    }

    public function drain(int $limit = 100): array
    {
        if (! Schema::hasTable('commission_return_sync_outboxes')) {
            return ['attempted' => 0, 'processed' => 0, 'failed' => 0];
        }

        $returnIds = CommissionReturnSyncOutbox::query()
            ->where(fn ($query) => $query
                ->whereNull('available_at')
                ->orWhere('available_at', '<=', now()))
            ->orderBy('id')
            ->limit(max(1, $limit * 5))
            ->pluck('sales_return_document_id')
            ->unique()
            ->take($limit)
            ->values();

        $result = ['attempted' => 0, 'processed' => 0, 'failed' => 0];

        foreach ($returnIds as $returnId) {
            $result['attempted']++;

            try {
                if ($this->processReturn((int) $returnId)) {
                    $result['processed']++;
                }
            } catch (\Throwable $exception) {
                $result['failed']++;
                report($exception);
            }
        }

        return $result;
    }

    private function recordFailure(int $returnId, \Throwable $exception): void
    {
        if (! Schema::hasTable('commission_return_sync_outboxes')) {
            return;
        }

        $rows = CommissionReturnSyncOutbox::query()
            ->where('sales_return_document_id', $returnId)
            ->orderBy('id')
            ->get();

        if ($rows->isEmpty()) {
            return;
        }

        $attempt = ((int) $rows->max('attempts')) + 1;
        $delaySeconds = min(300, 5 * (2 ** min(max($attempt - 1, 0), 6)));

        CommissionReturnSyncOutbox::query()
            ->where('sales_return_document_id', $returnId)
            ->update([
                'attempts' => $attempt,
                'last_error' => mb_substr($exception->getMessage(), 0, 4000),
                'available_at' => now()->addSeconds($delaySeconds),
                'updated_at' => now(),
            ]);

        $return = SalesReturnDocument::query()->find($returnId);
        if ($return?->invoice_id) {
            $this->dirtyMarker->markInvoiceId((int) $return->invoice_id);
        }

        CommissionReconciliationWarning::query()->updateOrCreate(
            ['identity_key' => 'return-sync-failed:'.$returnId],
            [
                'code' => 'return_sync_failed',
                'invoice_id' => $return?->invoice_id,
                'sales_return_document_id' => $return?->id,
                'message' => 'همگام‌سازی خودکار اثر پورسانت برگشت از فروش ناموفق بود و برای تلاش مجدد در صف قرار گرفت.',
                'context' => [
                    'attempt' => $attempt,
                    'retry_in_seconds' => $delaySeconds,
                    'error' => mb_substr($exception->getMessage(), 0, 1000),
                ],
                'resolved_at' => null,
            ],
        );

        Log::error('Commission return outbox delivery failed.', [
            'sales_return_document_id' => $returnId,
            'attempt' => $attempt,
            'retry_in_seconds' => $delaySeconds,
            'exception' => $exception->getMessage(),
        ]);
    }

    private function sourceMissingWarning(int $returnId): void
    {
        CommissionReconciliationWarning::query()->updateOrCreate(
            ['identity_key' => 'return-sync-source-missing:'.$returnId],
            [
                'code' => 'return_sync_source_missing',
                'invoice_id' => null,
                'sales_return_document_id' => null,
                'message' => 'سند برگشت از فروش قبل از پردازش صف پورسانت حذف شده است.',
                'context' => ['sales_return_document_id' => $returnId],
                'resolved_at' => null,
            ],
        );
    }
}
