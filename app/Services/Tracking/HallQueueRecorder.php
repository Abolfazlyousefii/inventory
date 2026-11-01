<?php

namespace App\Services\Tracking;

use App\Models\Invoice;
use App\Models\PreinvoiceOrder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/** A dashboard sidecar: failures must never roll back a committed sale. */
final class HallQueueRecorder
{
    private array $available = [];

    public function capture(Invoice|PreinvoiceOrder $model, ?string $fromStatus, ?string $overrideStatus = null): void
    {
        $type = $model instanceof Invoice ? 'invoice' : 'preinvoice';
        $status = $overrideStatus ?? (string) $model->status;
        if ($type === 'preinvoice' && $model->status === 'converted_to_invoice') {
            return;
        }
        $from = $fromStatus === null ? null : HallManagementDashboard::stage($type, $fromStatus);
        $to = HallManagementDashboard::stage($type, $status);
        if ($from === $to || ! $model->getKey()) {
            return;
        }

        // Capture scalars now; subsequent saves may mutate this same model before commit.
        $connectionName = $model->getConnectionName() ?: config('database.default');
        $row = [
            'workflow_key' => 'order:'.($model instanceof Invoice ? ($model->preinvoice_order_id ?: 'invoice:'.$model->getKey()) : $model->getKey()),
            'source_type' => $type, 'source_id' => (int) $model->getKey(),
            'document_number' => (string) ($model->uuid ?: $model->getKey()),
            'from_stage' => $from, 'to_stage' => $to,
            'status_from' => $fromStatus, 'status_to' => $status, 'entered_at' => now()->format('Y-m-d H:i:s.u'),
        ];
        try {
            DB::connection($connectionName)->afterCommit(function () use ($connectionName, $row): void {
                try {
                    $db = DB::connection($connectionName);
                    $exists = $this->available[$connectionName] ??= $db->getSchemaBuilder()->hasTable('hall_queue_transitions');
                    if ($exists) {
                        $db->table('hall_queue_transitions')->insert($row);
                    }
                } catch (Throwable $exception) {
                    $this->warn($row['workflow_key'], $exception);
                }
            });
        } catch (Throwable $exception) {
            $this->warn($row['workflow_key'], $exception);
        }
    }

    private function warn(string $key, Throwable $exception): void
    {
        try {
            Log::warning('Hall queue timestamp was not recorded.', ['workflow_key' => $key, 'exception' => $exception::class]);
        } catch (Throwable) {
            // A display diagnostic must not turn a committed sale into an HTTP error.
        }
    }
}
