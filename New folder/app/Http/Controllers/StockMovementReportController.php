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

        if (in_array($reason, ['purchase','sale','return','transfer','adjustment'], true)) {
            $query->where('reason', $reason);
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
