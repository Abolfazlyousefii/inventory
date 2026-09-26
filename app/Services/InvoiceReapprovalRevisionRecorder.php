<?php

namespace App\Services;

use App\Models\Invoice;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class InvoiceReapprovalRevisionRecorder
{
    public function record(Invoice $invoice, Collection $before, Collection $after, int $oldTotal, int $newTotal, ?string $reason, ?string $note, int $actorId): void
    {
        if (! DB::getSchemaBuilder()->hasTable('invoice_collection_revisions')) {
            return;
        }

        $beforeById = $before->keyBy('id');
        $afterById = $after->keyBy('id');
        $rows = [];
        foreach ($beforeById->keys()->merge($afterById->keys())->unique() as $id) {
            $old = $beforeById->get($id);
            $new = $afterById->get($id);
            if ($old && $new && (int) $old->quantity === (int) $new->quantity
                && (int) $old->price === (int) $new->price
                && (int) ($old->line_discount_amount ?? 0) === (int) ($new->line_discount_amount ?? 0)) {
                continue;
            }

            $item = $new ?? $old;
            $rows[] = [
                'invoice_item_id' => $new?->id,
                'product_id' => $item->product_id,
                'product_variant_id' => $item->variant_id,
                'change_type' => $old === null ? 'added' : ($new === null ? 'removed' : 'multiple_changes'),
                'product_name_snapshot' => $item->product?->name,
                'variant_name_snapshot' => $item->variant?->variant_name ?: $item->variant?->variety_name,
                'sku_snapshot' => $item->variant?->variant_code ?: $item->variant?->variety_code,
                'old_quantity' => $old?->quantity,
                'new_quantity' => $new?->quantity,
                'old_price' => $old?->price,
                'new_price' => $new?->price,
                'old_discount' => $old?->line_discount_amount,
                'new_discount' => $new?->line_discount_amount,
                'old_line_total' => $old?->line_total,
                'new_line_total' => $new?->line_total,
                'created_at' => now(),
                'updated_at' => now(),
            ];
        }

        $revisionId = DB::table('invoice_collection_revisions')->insertGetId([
            'invoice_id' => $invoice->id,
            'revision_number' => ((int) DB::table('invoice_collection_revisions')->where('invoice_id', $invoice->id)->max('revision_number')) + 1,
            'old_total' => $oldTotal,
            'new_total' => $newTotal,
            'reason_type' => $reason,
            'reason_note' => $note,
            'changed_by' => $actorId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        foreach ($rows as $row) {
            DB::table('invoice_collection_revision_items')->insert($row + ['invoice_collection_revision_id' => $revisionId]);
        }
    }
}
