<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\Warehouse;
use App\Models\WarehouseLocation;
use App\Models\WarehouseLocationMovement;
use App\Services\WarehouseMapService;
use App\Support\PermissionCatalog;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Validation\ValidationException;

class WarehouseMapController extends Controller
{
    private const MANAGER_ROLES = ['admin', 'Admin', 'ادمین', 'Manager', 'manager', 'مدیر', 'warehouse', 'انباردار', 'StorageUser', 'StorageManager'];

    public function index(Request $request, WarehouseMapService $service)
    {
        $warehouseId = $this->selectedWarehouseId($request);
        $canManage = $this->canManageWarehouseMap();

        $warehouses = Warehouse::query()->where('is_active', true)->orderBy('name')->get();
        $categories = Category::query()->orderBy('name')->get();
        $mainCategories = Category::query()->whereNull('parent_id')->orderBy('name')->get();
        $users = User::query()->orderBy('name')->get(['id', 'name']);

        $locations = WarehouseLocation::query()
            ->with(['warehouse', 'stocks.variant.product'])
            ->withCount(['stocks as variants_count' => fn ($q) => $q->where('quantity', '>', 0)])
            ->withSum(['stocks as total_quantity' => fn ($q) => $q->where('quantity', '>', 0)], 'quantity')
            ->when($warehouseId, fn ($q) => $q->where('warehouse_id', $warehouseId))
            ->when($request->filled('zone'), fn ($q) => $q->where('zone', 'like', '%'.$this->normalizeFa($request->zone).'%'))
            ->when($request->filled('rack'), fn ($q) => $q->where('rack', 'like', '%'.$this->normalizeFa($request->rack).'%'))
            ->when($request->filled('box'), fn ($q) => $q->where('box', 'like', '%'.$this->normalizeFa($request->box).'%'))
            ->when($request->filled('location_q'), fn ($q) => $q->where('code', 'like', '%'.$this->normalizeFa($request->location_q).'%'))
            ->orderBy('code')
            ->paginate(15, ['*'], 'locations_page')->withQueryString();

        $allLocations = WarehouseLocation::query()
            ->where('warehouse_id', $warehouseId)
            ->where('is_active', true)
            ->orderBy('code')
            ->get();

        $variantRows = $this->variantRows($request, $service, $warehouseId);
        $unmappedRows = $variantRows->filter(fn ($row) => $row['unmapped'] > 0)->values();
        $summary = $this->summary($variantRows, $warehouseId);

        $movements = $this->movementsQuery($request, $warehouseId)
            ->latest()
            ->paginate(25, ['*'], 'history_page')->withQueryString();

        $recentTransfers = WarehouseLocationMovement::query()
            ->with(['variant.product', 'fromLocation', 'toLocation', 'user'])
            ->where('warehouse_id', $warehouseId)
            ->where('type', 'transfer')
            ->latest()
            ->limit(10)
            ->get();

        $movementTypes = $this->movementTypes();
        $activeTab = $request->get('tab', 'locations');

        return view('warehouse-map.index', compact(
            'warehouses', 'categories', 'mainCategories', 'users', 'locations', 'allLocations', 'warehouseId', 'variantRows',
            'unmappedRows', 'summary', 'movements', 'recentTransfers', 'movementTypes', 'activeTab', 'canManage'
        ));
    }


    public function categoryChildren(Category $category)
    {
        return response()->json($category->children()->get(['id', 'name', 'code', 'parent_id']));
    }

    public function categoryProducts(Category $category, Request $request)
    {
        $query = Product::query()
            ->where('category_id', $category->id)
            ->where('is_sellable', true)
            ->orderBy('name');

        if ($request->filled('q')) {
            $term = trim($this->normalizeFa($request->q));
            $query->where(function ($q) use ($term) {
                $q->where('name', 'like', "%{$term}%")
                    ->orWhere('code', 'like', "%{$term}%")
                    ->orWhere('sku', 'like', "%{$term}%")
                    ->orWhere('barcode', 'like', "%{$term}%");
            });
        }

        return response()->json($query->limit(100)->get(['id', 'name', 'code', 'sku', 'barcode']));
    }

    public function productVariants(Product $product, Request $request, WarehouseMapService $service)
    {
        $warehouseId = (int) $request->integer('warehouse_id', $this->selectedWarehouseId($request));

        $variants = $product->variants()
            ->active()
            ->with(['modelList', 'color'])
            ->orderBy('variant_name')
            ->get()
            ->map(function (ProductVariant $variant) use ($warehouseId, $service) {
                $total = $service->totalQuantity((int) $variant->id, $warehouseId);
                $mapped = $service->mappedQuantity((int) $variant->id, $warehouseId);
                $unmapped = $total - $mapped;
                $code = $variant->variant_code ?: ($variant->sku ?: ($variant->barcode ?: ($variant->variety_code ?: '')));
                $parts = collect([$variant->variant_name, $variant->modelList?->name, $variant->color?->name, $variant->variety_name])->filter()->unique()->values();

                return [
                    'id' => $variant->id,
                    'title' => $parts->implode(' / ') ?: 'تنوع اصلی',
                    'code' => $code,
                    'sku' => $variant->sku,
                    'barcode' => $variant->barcode,
                    'total_stock' => $total,
                    'mapped_quantity' => $mapped,
                    'unmapped_quantity' => $unmapped,
                    'has_mismatch' => $mapped > $total,
                    'option_text' => ($parts->implode(' / ') ?: 'تنوع اصلی') . ($code ? " - کد: {$code}" : '') . " - بدون مکان: {$unmapped}",
                ];
            });

        return response()->json($variants);
    }

    public function storeLocation(Request $request)
    {
        $this->authorizeManage();
        $data = $request->validate([
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'zone' => ['required', 'string', 'max:20'],
            'rack' => ['required', 'string', 'max:20'],
            'box' => ['required', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:1000'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        $zone = WarehouseLocation::normalizePart($this->normalizeFa($data['zone']), 'Z');
        $rack = WarehouseLocation::normalizePart($this->normalizeFa($data['rack']), 'R');
        $box = WarehouseLocation::normalizePart($this->normalizeFa($data['box']), 'B');

        $exists = WarehouseLocation::query()->where('warehouse_id', (int) $data['warehouse_id'])->where(compact('zone', 'rack', 'box'))->exists();
        if ($exists) {
            throw ValidationException::withMessages(['location' => 'این مکان قبلاً برای این انبار ثبت شده است.']);
        }

        try {
            WarehouseLocation::create([
                'warehouse_id' => (int) $data['warehouse_id'],
                'zone' => $zone,
                'rack' => $rack,
                'box' => $box,
                'code' => WarehouseLocation::makeCode($zone, $rack, $box),
                'description' => $data['description'] ?? null,
                'is_active' => $request->boolean('is_active', true),
            ]);
        } catch (QueryException $e) {
            throw ValidationException::withMessages(['location' => 'این مکان قبلاً برای این انبار ثبت شده است.']);
        }

        return back()->with('success', 'مکان انبار با موفقیت ثبت شد.');
    }

    public function updateLocation(Request $request, WarehouseLocation $location)
    {
        $this->authorizeManage();
        $data = $request->validate(['description' => ['nullable','string','max:1000'], 'is_active' => ['nullable','boolean']]);
        $location->update(['description' => $data['description'] ?? null, 'is_active' => $request->boolean('is_active')]);
        return back()->with('success', 'مکان انبار بروزرسانی شد.');
    }

    public function showLocation(WarehouseLocation $location)
    {
        $location->load(['warehouse', 'stocks' => fn ($q) => $q->where('quantity', '>', 0)->with('variant.product')]);
        return response()->json($location);
    }

    public function assign(Request $request, WarehouseMapService $service)
    {
        $this->authorizeManage();
        $data = $request->validate([
            'warehouse_id' => ['required','exists:warehouses,id'],
            'product_variant_id' => ['required','exists:product_variants,id'],
            'warehouse_location_id' => ['required','exists:warehouse_locations,id'],
            'quantity' => ['required','integer','min:1'],
            'note' => ['nullable','string','max:1000'],
        ]);
        $service->assignLocation((int)$data['product_variant_id'], (int)$data['warehouse_id'], (int)$data['warehouse_location_id'], (int)$data['quantity'], auth()->id(), $data['note'] ?? null);
        return back()->with('success', 'کالا به مکان انتخاب‌شده اضافه شد.');
    }

    public function transfer(Request $request, WarehouseMapService $service)
    {
        $this->authorizeManage();
        $data = $request->validate([
            'warehouse_id' => ['required','exists:warehouses,id'],
            'product_variant_id' => ['required','exists:product_variants,id'],
            'from_location_id' => ['required','exists:warehouse_locations,id'],
            'to_location_id' => ['required','exists:warehouse_locations,id', 'different:from_location_id'],
            'quantity' => ['required','integer','min:1'],
            'note' => ['nullable','string','max:1000'],
        ]);
        $service->transfer((int)$data['product_variant_id'], (int)$data['warehouse_id'], (int)$data['from_location_id'], (int)$data['to_location_id'], (int)$data['quantity'], auth()->id(), $data['note'] ?? null);
        return back()->with('success', 'جابه‌جایی مکانی بدون تغییر موجودی کل ثبت شد.');
    }

    public function history(Request $request)
    {
        return redirect()->route('warehouse-map.index', array_merge($request->query(), ['tab' => 'history']));
    }

    private function variantRows(Request $request, WarehouseMapService $service, int $warehouseId): Collection
    {
        $variants = ProductVariant::query()
            ->with(['product.category', 'locationStocks' => fn ($q) => $q->where('warehouse_id', $warehouseId)->with('location')])
            ->whereHas('product')
            ->when($request->filled('category_id'), fn ($q) => $q->whereHas('product', fn ($p) => $p->where('category_id', $request->category_id)))
            ->when($request->filled('product_id'), fn ($q) => $q->where('product_id', $request->product_id))
            ->when($request->filled('q'), function ($q) use ($request) {
                $term = trim((string) $this->normalizeFa($request->q));
                $q->where(function ($qq) use ($term) {
                    $qq->where('variant_name', 'like', "%{$term}%")
                        ->orWhere('variant_code', 'like', "%{$term}%")
                        ->orWhereHas('product', fn ($p) => $p->where('name', 'like', "%{$term}%")->orWhere('code', 'like', "%{$term}%")->orWhere('sku', 'like', "%{$term}%")->orWhere('barcode', 'like', "%{$term}%"));
                });
            })
            ->latest('id')
            ->get();

        return $variants->map(function (ProductVariant $variant) use ($service, $warehouseId) {
            $total = $service->totalQuantity((int) $variant->id, $warehouseId);
            $mapped = (int) $variant->locationStocks->sum('quantity');
            $locations = $variant->locationStocks->where('quantity', '>', 0)->values();
            $unmapped = $total - $mapped;
            return ['variant' => $variant, 'total' => $total, 'mapped' => $mapped, 'unmapped' => $unmapped, 'locations' => $locations, 'multi' => $locations->count() > 1, 'mismatch' => $mapped > $total];
        })->filter(function ($row) use ($request) {
            return match ($request->get('map_status')) {
                'mapped' => $row['mapped'] > 0,
                'unmapped' => $row['unmapped'] > 0,
                'multi' => $row['multi'],
                'mismatch' => $row['mismatch'],
                default => true,
            };
        })->values();
    }

    private function summary(Collection $rows, int $warehouseId): array
    {
        return [
            'locations' => WarehouseLocation::query()->where('warehouse_id', $warehouseId)->where('is_active', true)->count(),
            'mapped_variants' => $rows->where('mapped', '>', 0)->count(),
            'unmapped_variants' => $rows->where('unmapped', '>', 0)->count(),
            'multi_location_variants' => $rows->where('multi', true)->count(),
            'mismatches' => $rows->where('mismatch', true)->count(),
        ];
    }

    private function movementsQuery(Request $request, int $warehouseId)
    {
        return WarehouseLocationMovement::query()
            ->with(['warehouse', 'variant.product', 'fromLocation', 'toLocation', 'user'])
            ->where('warehouse_id', $warehouseId)
            ->when($request->filled('history_type'), fn ($q) => $q->where('type', $request->history_type))
            ->when($request->filled('history_variant_id'), fn ($q) => $q->where('product_variant_id', $request->history_variant_id))
            ->when($request->filled('from_location_id'), fn ($q) => $q->where('from_location_id', $request->from_location_id))
            ->when($request->filled('to_location_id'), fn ($q) => $q->where('to_location_id', $request->to_location_id))
            ->when($request->filled('user_id'), fn ($q) => $q->where('user_id', $request->user_id))
            ->when($request->filled('date_from'), fn ($q) => $q->whereDate('created_at', '>=', $request->date_from))
            ->when($request->filled('date_to'), fn ($q) => $q->whereDate('created_at', '<=', $request->date_to));
    }

    private function selectedWarehouseId(Request $request): int
    {
        return (int) $request->integer('warehouse_id', Warehouse::query()->where('type', 'central')->value('id') ?: Warehouse::query()->value('id'));
    }

    private function movementTypes(): array
    {
        return ['initial_mapping' => 'تخصیص اولیه', 'purchase_receive' => 'ورود از خرید', 'sale_pick' => 'برداشت فروش', 'transfer' => 'جابه‌جایی', 'adjustment' => 'اصلاح موجودی مکانی', 'manual_increase' => 'افزایش دستی', 'manual_decrease' => 'کاهش دستی'];
    }

    private function authorizeManage(): void
    {
        abort_unless($this->canManageWarehouseMap(), 403, 'شما اجازه مدیریت نقشه انبار را ندارید.');
    }

    private function canManageWarehouseMap(): bool
    {
        $user = auth()->user();
        return $user && (PermissionCatalog::userHasPermission($user, '*')
            || (method_exists($user, 'hasAnyRole') && $user->hasAnyRole(self::MANAGER_ROLES)));
    }

    private function normalizeFa(?string $value): string
    {
        return strtr((string) $value, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
    }
}
