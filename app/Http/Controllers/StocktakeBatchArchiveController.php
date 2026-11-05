<?php

namespace App\Http\Controllers;

use App\Models\StockCountDocument;
use Illuminate\Contracts\View\View;
use Illuminate\Support\Facades\DB;

/** Read-only grouping of already finalized legacy per-product stocktake documents. */
final class StocktakeBatchArchiveController extends Controller
{
    public function index(): View
    {
        $batches = DB::table('stocktake_requests')
            ->where('operation', 'finalize')
            ->whereNotNull('response')
            ->orderByDesc('id')
            ->paginate(10);

        $documentIdsByBatch = $batches->getCollection()->mapWithKeys(
            fn ($request) => [(int) $request->id => self::documentIds((string) $request->response)]
        );

        $allIds = $documentIdsByBatch->flatten()->unique()->values()->all();
        $documents = StockCountDocument::query()
            ->with('product:id,name')
            ->whereIn('id', $allIds)
            ->get()
            ->keyBy('id');

        $batches->getCollection()->transform(function ($request) use ($documentIdsByBatch, $documents) {
            $ids = $documentIdsByBatch->get((int) $request->id, []);
            $found = collect($ids)->map(fn (int $id) => $documents->get($id))->filter()->values();

            return (object) [
                'id' => (int) $request->id,
                'created_at' => $request->created_at,
                'document_count' => count($ids),
                'missing_count' => count($ids) - $found->count(),
                'documents' => $found,
                'total_increase' => (int) $found->sum('total_increase'),
                'total_decrease' => (int) $found->sum('total_decrease'),
            ];
        });

        return view('stocktake.batches', compact('batches'));
    }

    /** @return list<int> */
    public static function documentIds(string $response): array
    {
        $payload = json_decode($response, true);
        if (! is_array($payload) || ! isset($payload['documents']) || ! is_array($payload['documents'])) {
            return [];
        }

        $ids = [];
        foreach ($payload['documents'] as $entry) {
            if (! is_array($entry)) {
                continue;
            }
            $id = filter_var($entry['document_id'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
            if ($id !== false) {
                $ids[(int) $id] = (int) $id;
            }
        }

        return array_values($ids);
    }
}
