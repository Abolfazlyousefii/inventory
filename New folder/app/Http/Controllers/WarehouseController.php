<?php

namespace App\Http\Controllers;

use App\Models\Warehouse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class WarehouseController extends Controller
{
    public function index()
    {
        $warehouses = Warehouse::query()
            ->whereNull('parent_id')
            ->withCount('stocks')
            ->orderBy('type')
            ->orderBy('name')
            ->get();

        return view('warehouses.index', compact('warehouses'));
    }

    public function edit(Warehouse $warehouse)
    {
        if ($warehouse->isPersonnelRoot()) {
            return redirect()->route('warehouses.personnel.index', $warehouse);
        }

        return view('warehouses.edit', compact('warehouse'));
    }

    public function update(Request $request, Warehouse $warehouse)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        $warehouse->update(['name' => $data['name']]);

        return redirect()->route('warehouses.index')->with('success', 'نام انبار با موفقیت ویرایش شد.');
    }

    public function destroy(Warehouse $warehouse)
    {
        if ($warehouse->isPersonnelRoot()) {
            return back()->withErrors(['warehouse' => 'برای حذف انبار پرسنل، ابتدا زیرمجموعه‌های پرسنل را مدیریت کنید.']);
        }

        if ($warehouse->children()->exists()) {
            return back()->withErrors(['warehouse' => 'ابتدا زیرمجموعه‌های این انبار را حذف کنید.']);
        }

        try {
            DB::transaction(function () use ($warehouse) {
                $warehouse->delete();
            });
        } catch (\Throwable $e) {
            return back()->withErrors(['warehouse' => 'این انبار به سند حواله یا موجودی متصل است و قابل حذف نیست.']);
        }

        return back()->with('success', 'انبار حذف شد.');
    }

    public function personnelIndex(Warehouse $warehouse)
    {
        abort_unless($warehouse->isPersonnelRoot(), 404);

        $personnels = Warehouse::query()
            ->where('parent_id', $warehouse->id)
            ->withCount(['stocks as stocked_products_count' => fn ($q) => $q->where('quantity', '>', 0)])
            ->orderBy('name')
            ->get();

        return view('warehouses.personnel', compact('warehouse', 'personnels'));
    }

    public function personnelStore(Request $request, Warehouse $warehouse)
    {
        abort_unless($warehouse->isPersonnelRoot(), 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
        ]);

        Warehouse::create([
            'name' => $data['name'],
            'type' => 'personnel',
            'personnel_name' => $data['name'],
            'parent_id' => $warehouse->id,
            'is_active' => true,
        ]);

        return back()->with('success', 'پرسنل به انبار پرسنل اضافه شد.');
    }

    public function personnelShow(Warehouse $warehouse, Warehouse $personnel)
    {
        abort_unless($warehouse->isPersonnelRoot() && $personnel->parent_id === $warehouse->id, 404);

        $stocks = $personnel->stocks()
            ->with('product.category')
            ->where('quantity', '>', 0)
            ->orderByDesc('quantity')
            ->get();

        return view('warehouses.personnel-show', compact('warehouse', 'personnel', 'stocks'));
    }
}
