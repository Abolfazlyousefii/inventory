<?php

namespace App\Http\Controllers;

use App\Models\Product;

class ReportController extends Controller
{
    public function lowStock()
    {
        $products = Product::with('category')
            ->whereColumn('stock', '<=', 'low_stock_threshold')
            ->orderBy('stock')
            ->paginate(20);

        return view('reports.low_stock', compact('products'));
    }
}
