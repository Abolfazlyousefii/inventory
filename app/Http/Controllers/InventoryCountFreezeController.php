<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Services\InventoryCountFreezeService;
use Illuminate\Http\Request;

class InventoryCountFreezeController extends Controller
{
    public function index(Request $request, InventoryCountFreezeService $service)
    {
        abort_unless($request->user()?->isAdministrator(), 403);

        return view('inventory-count-freeze.index', ['state' => $service->state(), 'categories' => Category::with('parent')->orderBy('name')->get()]);
    }

    public function preview(Request $request, InventoryCountFreezeService $service)
    {
        abort_unless($request->user()?->isAdministrator(), 403);
        $data = $this->validated($request);

        return response()->json($service->preview($data['category_ids'] ?? [], $request->boolean('all_products')));
    }

    public function update(Request $request, InventoryCountFreezeService $service)
    {
        abort_unless($request->user()?->isAdministrator(), 403);
        $data = $this->validated($request);
        if ($request->boolean('enabled')) {
            $request->validate(['acknowledge' => 'accepted', 'version' => 'required|integer|min:0']);
            $result = $service->activate($data['category_ids'] ?? [], $request->boolean('all_products'), $request->user(), (int) $request->input('version'));
            $message = 'دستهٔ «'.$service->scopeLabel().'» بسته شد؛ '.$result['released_rows'].' رزرو آزاد و '.$result['archived_rows'].' ردیف تاریخی بدون تغییر موجودی بایگانی شد. اسناد درگیر به پیش‌نویس برگشتند. فروش فقط با بازکردن صریح دسته دوباره فعال می‌شود.';
        } else {
            $request->validate(['version' => 'required|integer|min:0']);
            $service->deactivate($request->user(), (int) $request->input('version'));
            $message = 'انبارگردانی پایان یافت. رزروها خودکار برنمی‌گردند؛ پیش‌فاکتورها باید دوباره ثبت و بررسی شوند.';
        }

        return redirect()->route('inventory-count-freeze.index')->with('success', $message);
    }

    private function validated(Request $request): array
    {
        return $request->validate(['enabled' => 'sometimes|boolean', 'all_products' => 'sometimes|boolean', 'category_ids' => 'sometimes|array|max:1000', 'category_ids.*' => 'integer|distinct|exists:categories,id']);
    }
}
