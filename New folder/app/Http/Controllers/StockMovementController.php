<?php

namespace App\Http\Controllers;

use App\Models\Product;
use App\Models\StockMovement;
use App\Services\WarehouseStockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class StockMovementController extends Controller
{
    public function create(Product $product)
    {
        return view('movements.create', compact('product'));
    }

    public function store(Request $request, Product $product)
    {
        $data = $request->validate([
            'type' => ['required', 'in:in,out'],
            'reason' => ['required', 'in:purchase,sale,return,transfer,adjustment'],
            'quantity' => ['required', 'integer', 'min:1'],
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($data, $product) {
            $p = Product::whereKey($product->id)->lockForUpdate()->firstOrFail();

            if ($p->variants()->exists()) {
                abort(422, 'ثبت گردش موجودی سطح کالا برای کالای دارای تنوع مجاز نیست.');
            }

            $before = (int) $p->stock;
            $qty = (int) $data['quantity'];

            if ($data['type'] === 'out' && $before < $qty) {
                abort(422, 'موجودی کافی نیست.');
            }

            $after = ($data['type'] === 'in')
                ? $before + $qty
                : $before - $qty;

            StockMovement::create([
                'product_id' => $p->id,
                'user_id' => auth()->id(),
                'type' => $data['type'],
                'reason' => $data['reason'],
                'quantity' => $qty,
                'stock_before' => $before,
                'stock_after' => $after,
                'reference' => $data['reference'] ?? null,
                'note' => $data['note'] ?? null,
            ]);

            WarehouseStockService::change(
                WarehouseStockService::centralWarehouseId(),
                (int) $p->id,
                $data['type'] === 'in' ? $qty : -$qty,
                null
            );
        });

        return redirect()
            ->route('products.index')
            ->with('success', 'گردش انبار ثبت شد و موجودی بروزرسانی شد.');
    }
}