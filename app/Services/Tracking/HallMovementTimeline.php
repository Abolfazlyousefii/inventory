<?php

namespace App\Services\Tracking;

use App\Models\Invoice;
use App\Models\PreinvoiceOrder;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only, on-demand timeline for an already-authorized active Hall document.
 * Modern queue ledger wins for overlapping dates; older status audits are kept
 * only before the first ledger entry, so each movement is counted once.
 */
final class HallMovementTimeline
{
    public function page(array $document, int $page = 1): array
    {
        $workflowKey = (string) $document['key'];
        $invoiceId = $document['type'] === 'invoice' ? (int) $document['source_id'] : null;
        $orderId = preg_match('/^order:([0-9]+)$/', $workflowKey, $matches)
            ? (int) $matches[1] : null;

        $ledger = Schema::hasTable('hall_queue_transitions')
            ? DB::table('hall_queue_transitions')->where('workflow_key', $workflowKey)
                ->orderBy('entered_at')->orderBy('id')
                ->get(['id', 'source_type', 'status_from', 'status_to', 'from_stage', 'to_stage', 'entered_at'])
            : collect();

        $events = [];
        foreach ($ledger as $event) {
            $from = $event->from_stage;
            $to = $event->to_stage;
            if ($from === $to) {
                continue;
            }
            $events[] = $this->event(
                'hall:'.$event->id, (string) $event->source_type,
                $from, $to, $event->status_to, $event->entered_at
            );
        }

        $firstLedgerAt = $ledger->first()?->entered_at;
        if ($orderId !== null) {
            $old = DB::table('warehouse_review_logs')
                ->where('preinvoice_order_id', $orderId)->whereNotNull('status_to')
                ->orderBy('created_at')->orderBy('id')
                ->get(['id', 'status_from', 'status_to', 'created_at']);
            foreach ($old as $row) {
                if ($firstLedgerAt !== null && $row->created_at >= $firstLedgerAt) {
                    continue;
                }
                $from = HallManagementDashboard::stage('preinvoice', (string) ($row->status_from ?? ''));
                $to = HallManagementDashboard::stage('preinvoice', (string) $row->status_to);
                if ($from === $to) {
                    continue;
                }
                $events[] = $this->event('preinvoice:'.$row->id, 'preinvoice', $from, $to, $row->status_to, $row->created_at);
            }
        }

        if ($invoiceId !== null) {
            $old = DB::table('sales_havaleh_histories')
                ->where('invoice_id', $invoiceId)->where('field_name', 'status')
                ->orderBy('done_at')->orderBy('id')
                ->get(['id', 'old_value', 'new_value', 'done_at']);
            foreach ($old as $row) {
                if ($firstLedgerAt !== null && $row->done_at >= $firstLedgerAt) {
                    continue;
                }
                $from = HallManagementDashboard::stage('invoice', (string) ($row->old_value ?? ''));
                $to = HallManagementDashboard::stage('invoice', (string) ($row->new_value ?? ''));
                if ($from === $to) {
                    continue;
                }
                $events[] = $this->event('invoice:'.$row->id, 'invoice', $from, $to, $row->new_value, $row->done_at);
            }
        }

        usort($events, static fn (array $a, array $b) => strcmp($b['at'], $a['at']) ?: strcmp($b['id'], $a['id']));

        $perPage = 30;
        $total = count($events);

        return [
            'document_key' => $workflowKey,
            'transfer_count' => count(array_filter($events, static fn ($e) =>
                $e['from_stage'] !== null && $e['to_stage'] !== null
            )),
            'record_count' => $total,
            'page' => $page,
            'has_more' => $page * $perPage < $total,
            'events' => array_slice($events, ($page - 1) * $perPage, $perPage),
        ];
    }

    private function event(
        string $id,
        string $type,
        ?string $from,
        ?string $to,
        ?string $status,
        string $at,
    ): array {
        $stages = [
            'draft' => 'فروش / پیش‌نویس',
            'reserved' => 'رزرو فعال',
            'finance' => 'مالی',
            'warehouse' => 'انبار',
            'reapproval' => 'تأیید مجدد مالی',
            'shipping' => 'ارسال',
        ];
        $labels = $type === 'invoice' ? Invoice::statusLabels() : PreinvoiceOrder::statusLabels();

        return [
            'id' => $id,
            'at' => CarbonImmutable::parse($at, config('app.timezone'))->toIso8601String(),
            'from_stage' => $from,
            'to_stage' => $to,
            'from_label' => $stages[$from] ?? 'آغاز جریان',
            'to_label' => $stages[$to] ?? 'خروج از جریان',
            'status_label' => $labels[$status] ?? ($status ?? 'تغییر وضعیت'),
        ];
    }
}
