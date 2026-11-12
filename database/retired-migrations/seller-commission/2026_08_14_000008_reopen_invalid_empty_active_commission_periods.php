<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $periods = DB::table('commission_periods')
                ->where('end_at', '>', now())
                ->whereIn('status', ['review', 'closed', 'paid'])
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('commission_ledger_entries')->whereColumn('commission_ledger_entries.commission_period_id', 'commission_periods.id'))
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('commission_correction_entries')->whereColumn('commission_correction_entries.commission_period_id', 'commission_periods.id'))
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('commission_adjustments')->whereColumn('commission_adjustments.commission_period_id', 'commission_periods.id'))
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('commission_documents')->whereColumn('commission_documents.commission_period_id', 'commission_periods.id'))
                ->whereNotExists(fn ($query) => $query->selectRaw('1')->from('commission_settlements')->whereColumn('commission_settlements.commission_period_id', 'commission_periods.id'))
                ->lockForUpdate()
                ->get();

            foreach ($periods as $period) {
                DB::table('commission_period_events')->insert([
                    'commission_period_id' => $period->id,
                    'actor_id' => null,
                    'event_type' => 'invalid_empty_period_reopened',
                    'metadata' => json_encode([
                        'previous_status' => $period->status,
                        'previous_review_started_at' => $period->review_started_at,
                        'previous_closed_at' => $period->closed_at,
                        'previous_paid_at' => $period->paid_at,
                        'reason' => 'Active period had no commission activity, document, or settlement.',
                    ], JSON_UNESCAPED_UNICODE),
                    'created_at' => now(),
                ]);

                DB::table('commission_periods')->where('id', $period->id)->update([
                    'status' => 'open',
                    'review_started_by' => null,
                    'review_started_at' => null,
                    'total_net_sales_snapshot' => null,
                    'base_commission_snapshot' => null,
                    'campaign_commission_snapshot' => null,
                    'return_reversal_snapshot' => null,
                    'seller_correction_snapshot' => null,
                    'manual_adjustment_snapshot' => null,
                    'approved_commission_snapshot' => null,
                    'seller_count_snapshot' => null,
                    'document_count_snapshot' => null,
                    'close_fingerprint' => null,
                    'closed_by' => null,
                    'closed_at' => null,
                    'paid_by' => null,
                    'paid_at' => null,
                    'updated_at' => now(),
                ]);
            }
        }, 3);
    }

    public function down(): void
    {
        // This is a one-way consistency repair; historical workflow state is retained in commission_period_events.
    }
};
