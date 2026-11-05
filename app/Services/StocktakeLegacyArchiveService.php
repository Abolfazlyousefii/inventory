<?php

namespace App\Services;

use App\Http\Controllers\StocktakeBatchArchiveController;
use App\Models\Category;
use App\Models\StockCountDocument;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only projections of historical "finalize all" operations.
 *
 * Never edits document headers, document items, stock movements or warehouse stock.
 * A group is eligible only if every saved source document is still present,
 * finalized and belongs to exactly one product per document in one category,
 * warehouse and document date.
 */
final class StocktakeLegacyArchiveService
{
    /** @return Collection<int, object> Keyed by stocktake_requests.id. */
    public function eligibleGroups(): Collection
    {
        if (! Schema::hasTable('stocktake_requests') || ! Schema::hasTable('categories')) {
            return collect();
        }

        $requests = DB::table('stocktake_requests')
            ->where('operation', 'finalize')
            ->whereNotNull('response')
            ->orderByDesc('id')
            ->get(['id', 'user_id', 'response', 'created_at']);

        $idLists = [];
        foreach ($requests as $request) {
            $ids = StocktakeBatchArchiveController::documentIds((string) $request->response);
            // The legacy endpoint accepted no more than 500 products per batch.
            if (count($ids) >= 2 && count($ids) <= 500) {
                $idLists[(int) $request->id] = $ids;
            }
        }
        if ($idLists === []) {
            return collect();
        }

        $allIds = array_values(array_unique(array_merge(...array_values($idLists))));
        $documents = StockCountDocument::query()
            ->with(['product:id,name,category_id', 'warehouse:id,name', 'creator:id,name'])
            ->withCount('items')
            ->whereIntegerInRaw('id', $allIds)
            ->get()
            ->keyBy('id');

        $groups = collect();
        $claimedIds = [];
        foreach ($requests as $request) {
            $ids = $idLists[(int) $request->id] ?? null;
            if (! $ids || array_intersect($ids, $claimedIds) !== []) {
                continue;
            }
            $members = collect($ids)->map(fn (int $id) => $documents->get($id));
            if ($members->contains(null)) {
                continue; // Missing documents: keep original rows visible.
            }

            $first = $members->first();
            $categoryId = (int) ($first->product?->category_id ?? 0);
            $warehouseId = (int) $first->warehouse_id;
            $day = $first->document_date?->toDateString();
            if ($categoryId < 1 || ! $day) {
                continue;
            }
            $valid = $members->every(static fn ($document) =>
                $document->type === 'product'
                && $document->status === 'finalized'
                && $document->superseded_by_id === null
                && $document->product !== null
                && (int) $document->product->category_id === $categoryId
                && (int) $document->warehouse_id === $warehouseId
                && $document->document_date?->toDateString() === $day
            );
            if (! $valid || $members->pluck('product_id')->unique()->count() !== count($ids)) {
                continue; // Never hide potentially independent counting sessions.
            }
            $category = Category::query()->find($categoryId);
            if (! $category) {
                continue;
            }

            $group = (object) [
                'is_archive_group' => true,
                'batch_id' => (int) $request->id,
                'document_number' => 'BATCH-'.(int) $request->id, // UI identifier, not an official document number.
                'type' => 'category',
                'category_id' => $categoryId,
                'scope_name' => $category->name,
                'category' => $category,
                'product' => null,
                'warehouse_id' => $warehouseId,
                'warehouse' => $first->warehouse,
                'created_by' => $first->created_by,
                'creator' => $first->creator,
                'document_date' => $first->document_date,
                'status' => 'finalized',
                'items_count' => (int) $members->sum('items_count'),
                'variants_count' => (int) $members->sum('variants_count'),
                'counted_count' => (int) $members->sum('counted_count'),
                'zeroed_count' => (int) $members->sum('zeroed_count'),
                'total_increase' => (int) $members->sum('total_increase'),
                'total_decrease' => (int) $members->sum('total_decrease'),
                'sort_id' => max($ids),
                'child_document_ids' => $ids,
                'child_documents' => $members->sortBy('id')->values(),
            ];
            $groups->put((int) $request->id, $group);
            array_push($claimedIds, ...$ids);
        }

        return $groups;
    }

    public function find(int $batchId): ?object
    {
        return $this->eligibleGroups()->get($batchId);
    }
}
