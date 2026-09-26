<?php

namespace App\Services;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class FinanceReapprovalChangesService
{
    /** @return array<int, array{revisions: Collection, history: Collection, original_total: ?int}> */
    public function forInvoices(Collection $invoices): array
    {
        $ids = $invoices->pluck('id')->map(fn ($id) => (int) $id)->all();
        if ($ids === []) {
            return [];
        }

        $revisions = Schema::hasTable('invoice_collection_revisions')
            ? DB::table('invoice_collection_revisions as revisions')
                ->leftJoin('users as actors', 'actors.id', '=', 'revisions.changed_by')
                ->whereIn('revisions.invoice_id', $ids)
                ->orderBy('revisions.invoice_id')
                ->orderBy('revisions.revision_number')
                ->get(['revisions.*', 'actors.name as actor_name'])
            : collect();

        $items = $revisions->isNotEmpty() && Schema::hasTable('invoice_collection_revision_items')
            ? DB::table('invoice_collection_revision_items')
                ->whereIn('invoice_collection_revision_id', $revisions->pluck('id'))
                ->orderBy('id')
                ->get()
                ->groupBy('invoice_collection_revision_id')
            : collect();

        $revisions->each(fn ($revision) => $revision->items = $items->get($revision->id, collect()));
        $byInvoice = $revisions->groupBy('invoice_id');

        // Historical edits outside the collection workflow may have only a textual audit.
        // Show those records honestly when no structured revision is available.
        $history = Schema::hasTable('sales_havaleh_histories')
            ? DB::table('sales_havaleh_histories')
                ->whereIn('invoice_id', $ids)
                ->whereIn('action_type', [
                    'item_added', 'item_removed', 'item_quantity_increased',
                    'item_quantity_decreased', 'item_price_changed',
                    'invoice_items_updated', 'invoice_price_discount_changed',
                    'finance_invoice_edited',
                    'seller_invoice_header_changed',
                ])
                ->orderBy('id')
                ->get()
                ->groupBy('invoice_id')
            : collect();

        $result = [];
        foreach ($ids as $id) {
            $invoiceRevisions = $byInvoice->get($id, collect());
            $result[$id] = [
                'revisions' => $invoiceRevisions,
                'history' => $history->get($id, collect()),
                'original_total' => $invoiceRevisions->isEmpty() ? null : (int) $invoiceRevisions->first()->old_total,
            ];
        }

        return $result;
    }
}
