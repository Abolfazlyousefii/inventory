<?php

namespace App\Http\Controllers;

use App\Models\StockCountDocumentItem;
use App\Services\StocktakeLegacyArchiveService;
use Illuminate\Contracts\View\View;

/** A single read-only view of a historical category count finalized as multiple product documents. */
final class StocktakeLegacyArchiveController extends Controller
{
    public function __construct(private readonly StocktakeLegacyArchiveService $archive) {}

    public function show(int $batchId): View
    {
        $group = $this->archive->find($batchId);
        abort_unless($group, 404, 'این عملیات یک سند تاریخی یکپارچه قابل تأیید ندارد.');

        $items = StockCountDocumentItem::query()
            ->whereIntegerInRaw('document_id', $group->child_document_ids)
            ->orderBy('product_name_snapshot')
            ->orderBy('product_variant_id')
            ->paginate(80)
            ->withQueryString();

        return view('stocktake.legacy-group-show', compact('group', 'items'));
    }
}
