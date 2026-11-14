<?php

namespace App\Http\Controllers;

use App\Models\StockMovement;
use Illuminate\Http\Request;

class StockMovementReportController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string)$request->get('q', ''));
        $type = $request->get('type');       // in/out
        $reason = $request->get('reason');   // purchase/sale/...
        $dateFrom = $request->get('from');
        $dateTo = $request->get('to');

        $query = StockMovement::query()
            ->with(['product','user','warehouse','variant'])
            ->latest();

        if ($q !== '') {
            $query->whereHas('product', function ($p) use ($q) {
                $p->where('name', 'like', "%{$q}%")
                  ->orWhere('sku', 'like', "%{$q}%");
            });
        }

        if (in_array($type, ['in','out'], true)) {
            $query->where('type', $type);
        }

        // Group all return-related movement reasons, including purchase returns.
        // Purchase returns use "purchase_return", not the legacy "return" reason.
        $reasonFilters = [
            'purchase' => ['purchase'],
            'sale' => ['sale'],
            'return' => ['return', 'sales_return', 'purchase_return', 'purchase_return_restore'],
            'purchase_return' => ['purchase_return', 'purchase_return_restore'],
            'sales_return' => ['sales_return'],
            'transfer' => ['transfer'],
            'adjustment' => ['adjustment'],
        ];
        if (is_string($reason) && isset($reasonFilters[$reason])) {
            $query->whereIn('reason', $reasonFilters[$reason]);
        }

        if ($dateFrom) {
            $query->whereDate('created_at', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->whereDate('created_at', '<=', $dateTo);
        }

        $movements = $query->paginate(20)->withQueryString();

        $sourceLinks = $this->sourceLinks($movements->getCollection());

        return view('movements.index', compact('movements', 'sourceLinks'));
    }

    private function sourceLinks(\Illuminate\Support\Collection $movements): array
    {
        $user = auth()->user();
        if (! $user) return [];
        $links = [];
        $returns = $movements->where('reference_type', \App\Models\PurchaseReturn::class)->whereNotNull('reference_id');
        if ($returns->isNotEmpty() && \App\Support\PageAccessCatalog::userCanRoute($user, 'purchase-returns.show')) {
            $existing = \App\Models\PurchaseReturn::withTrashed()->whereIn('id', $returns->pluck('reference_id'))->pluck('id');
            foreach ($returns as $movement) if ($existing->contains($movement->reference_id)) $links[$movement->id] = route('purchase-returns.show', $movement->reference_id);
        }
        $inbound = $movements->where('reference_type', \App\Models\WarehouseInboundReceiptItem::class)->whereNotNull('reference_id');
        if ($inbound->isNotEmpty() && \App\Support\PageAccessCatalog::userCanRoute($user, 'warehouse.inbound.show')) {
            $receipts = \App\Models\WarehouseInboundReceiptItem::whereIn('id', $inbound->pluck('reference_id'))->with('receipt:id')->get()->keyBy('id');
            foreach ($inbound as $movement) if ($receipts->get($movement->reference_id)?->receipt) $links[$movement->id] = route('warehouse.inbound.show', $receipts[$movement->reference_id]->receipt_id);
        }
        $direct = $movements->where('reference_type', \App\Models\SalesReturnDocument::class)->whereNotNull('reference_id');
        $items = $movements->where('reference_type', \App\Models\SalesReturnDocumentItem::class)->whereNotNull('reference_id');
        if (($direct->isNotEmpty() || $items->isNotEmpty()) && \App\Support\PageAccessCatalog::userCanRoute($user, 'vouchers.return-from-sale.show')) {
            $documents = \App\Models\SalesReturnDocumentItem::whereIn('id', $items->pluck('reference_id'))->pluck('document_id','id');
            $existing = \App\Models\SalesReturnDocument::whereIn('id', $direct->pluck('reference_id')->merge($documents->values()))->pluck('id');
            foreach ($direct as $movement) if ($existing->contains($movement->reference_id)) $links[$movement->id] = route('vouchers.return-from-sale.show', $movement->reference_id);
            foreach ($items as $movement) if ($documents->has($movement->reference_id) && $existing->contains($documents[$movement->reference_id])) $links[$movement->id] = route('vouchers.return-from-sale.show', $documents[$movement->reference_id]);
        }
        return $links;
    }
}
