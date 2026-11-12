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
            ->with(['product','user'])
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

        return view('movements.index', compact('movements'));
    }
}
