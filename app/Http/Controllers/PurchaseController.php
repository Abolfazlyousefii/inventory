<?php

namespace App\Http\Controllers;

use App\Exports\PurchasesExport;
use App\Models\Category;
use App\Models\Customer;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\PurchaseItem;
use App\Models\StockMovement;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Services\ProductVariantStructureService;
use App\Services\SupplierLedgerService;
use App\Services\WarehouseStockService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Maatwebsite\Excel\Facades\Excel;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class PurchaseController extends Controller
{
    public function index(Request $request)
    {
        $supplierId = $request->integer('supplier_id');
        $dateFrom = $request->get('date_from');
        $dateTo = $request->get('date_to');

        $query = Purchase::with('supplier')
            ->withCount('items')
            ->when($supplierId, fn ($q) => $q->where('supplier_id', $supplierId))
            ->when($dateFrom, fn ($q) => $q->whereDate('purchased_at', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate('purchased_at', '<=', $dateTo));

        $totalAllAmount = (int) Purchase::sum('total_amount');
        $totalAllCount = (int) Purchase::count();

        $purchases = $query->latest('purchased_at')
            ->paginate(20)
            ->withQueryString();

        $suppliers = Supplier::orderBy('name')->get();

        return view('purchases.index', compact(
            'purchases',
            'suppliers',
            'totalAllAmount',
            'totalAllCount'
        ));
    }


    public function exportExcel(): BinaryFileResponse
    {
        $filename = 'purchases-' . now()->format('Ymd-His') . '.xlsx';

        return Excel::download(new PurchasesExport, $filename);
    }

    public function show(Purchase $purchase)
    {
        $purchase->load(['supplier', 'items.variant', 'items.product']);
        return view('purchases.show', compact('purchase'));
    }

    public function create()
    {
        $suppliers = $this->purchaseSupplierOptions();

        $categories = Category::query()
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'parent_id']);

        $products = Product::query()
            ->with($this->productVariantRelations())
            ->orderBy('name')
            ->get(['id', 'name', 'category_id', 'code', 'short_barcode', 'sku', 'models', 'has_colors']);

        $variantStructure = app(ProductVariantStructureService::class);
        $products->each(fn (Product $product) => $product->setRelation('variants', $variantStructure->validVariants($product)));

        $variants = $products->flatMap(function (Product $product) {
            return $product->variants->map(function ($v) {
                $design2 = substr((string) $v->variant_code, -2);
                if (!preg_match('/^\d{2}$/', $design2)) $design2 = '00';

                return [
                    'id' => (int) $v->id,
                    'product_id' => (int) $v->product_id,
                    'model_list_id' => $v->model_list_id ? (int) $v->model_list_id : 0,
                    'variant_code' => (string) $v->variant_code,
                    'variant_name' => (string) ($v->variant_name ?? ''),
                    'variety_name' => (string) ($v->variety_name ?? ''),
                    'variety_code' => (string) ($v->variety_code ?? ''),
                    'design2' => $design2,
                    'design_title' => (string) ($v->variety_name ?? ''),
                    'sell_price' => (int) ($v->sell_price ?? 0),
                    'buy_price' => is_null($v->buy_price) ? null : (int) $v->buy_price,
                    'stock' => (int) ($v->stock ?? 0),
                    'reserved' => (int) ($v->reserved ?? 0),
                    'model_name' => (string) ($v->modelList?->model_name ?? ''),
                    'model_code' => (string) ($v->modelList?->code ?? ''),
                    'display_code' => (string) ($v->variant_code ?? '-'),
                    'barcode' => (string) ($v->variant_code ?? ''),
                    'sku' => (string) ($v->variant_code ?? ''),
                    'is_active' => (bool) ($v->is_active ?? true),
                    'color_name' => '',
                    'color_code' => '',
                ];
            });
        });

        return view('purchases.create', [
            'suppliers' => $suppliers,
            'categories' => $categories,
            'products' => $products,
            'variants' => $variants,
            'purchase' => null,
        ]);
    }

    public function productVariants(Product $product)
    {
        $variants = app(ProductVariantStructureService::class)->validVariants($product)
            ->unique('id')
            ->values()
            ->map(fn (ProductVariant $variant) => $this->variantPayload($variant));

        return response()->json([
            'product_id' => (int) $product->id,
            'variants' => $variants,
        ]);
    }

    public function edit(Purchase $purchase)
    {
        $purchase->load(['items', 'items.product', 'items.variant']);

        $suppliers = $this->purchaseSupplierOptions();

        $categories = Category::query()
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'parent_id']);

        $products = Product::query()
            ->with($this->productVariantRelations())
            ->orderBy('name')
            ->get(['id', 'name', 'category_id', 'code', 'short_barcode', 'sku', 'models', 'has_colors']);

        $variantStructure = app(ProductVariantStructureService::class);
        $products->each(fn (Product $product) => $product->setRelation('variants', $variantStructure->validVariants($product)));

        $variants = $products->flatMap(function (Product $product) {
            return $product->variants->map(function ($v) {
                $design2 = substr((string) $v->variant_code, -2);
                if (!preg_match('/^\d{2}$/', $design2)) $design2 = '00';

                return [
                    'id' => (int) $v->id,
                    'product_id' => (int) $v->product_id,
                    'model_list_id' => $v->model_list_id ? (int) $v->model_list_id : 0,
                    'variant_code' => (string) $v->variant_code,
                    'variant_name' => (string) ($v->variant_name ?? ''),
                    'variety_name' => (string) ($v->variety_name ?? ''),
                    'variety_code' => (string) ($v->variety_code ?? ''),
                    'design2' => $design2,
                    'design_title' => (string) ($v->variety_name ?? ''),
                    'sell_price' => (int) ($v->sell_price ?? 0),
                    'buy_price' => is_null($v->buy_price) ? null : (int) $v->buy_price,
                    'stock' => (int) ($v->stock ?? 0),
                    'reserved' => (int) ($v->reserved ?? 0),
                    'model_name' => (string) ($v->modelList?->model_name ?? ''),
                    'model_code' => (string) ($v->modelList?->code ?? ''),
                    'display_code' => (string) ($v->variant_code ?? '-'),
                    'barcode' => (string) ($v->variant_code ?? ''),
                    'sku' => (string) ($v->variant_code ?? ''),
                    'is_active' => (bool) ($v->is_active ?? true),
                    'color_name' => '',
                    'color_code' => '',
                ];
            });
        });

        return view('purchases.create', [
            'suppliers' => $suppliers,
            'categories' => $categories,
            'products' => $products,
            'variants' => $variants,
            'purchase' => $purchase,
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validatePayload($request);

        DB::transaction(function () use ($data) {
            $centralWarehouseId = WarehouseStockService::centralWarehouseId();

            $purchase = Purchase::create($this->purchaseTablePayload([
                'supplier_id' => $data['supplier_id'],
                'warehouse_id' => $centralWarehouseId,
                'user_id' => auth()->id(),
                'purchased_at' => now(),
                'note' => $data['note'] ?? null,
                'subtotal_amount' => 0,
                'discount_type' => $data['invoice_discount_type'] ?? null,
                'discount_value' => (int) ($data['invoice_discount_value'] ?? 0),
                'total_discount' => 0,
                'total_amount' => 0,
            ]));

            $summary = $this->applyItems($purchase, $data, $centralWarehouseId);
            $purchase->update($this->purchaseTablePayload($summary));

            app(SupplierLedgerService::class)->syncPurchaseCredit($purchase->fresh());
        });

        return redirect()->route('purchases.index')->with('success', 'خرید کالا با موفقیت ثبت شد.');
    }

    public function update(Request $request, Purchase $purchase)
    {
        if (! $request->filled('supplier_id')) {
            $request->merge(['supplier_id' => $purchase->supplier_id]);
        }

        $data = $this->validatePayload($request, true);

        DB::transaction(function () use ($purchase, $data) {
            $purchase = Purchase::whereKey($purchase->id)
                ->lockForUpdate()
                ->firstOrFail();

            $purchase->update($this->purchaseTablePayload([
                'supplier_id' => $data['supplier_id'],
                'warehouse_id' => (int) $data['warehouse_id'],
                'purchased_at' => $data['purchased_at'] ?? $purchase->purchased_at,
                'note' => $data['note'] ?? null,
                'user_id' => auth()->id(),
                'discount_type' => $data['invoice_discount_type'] ?? null,
                'discount_value' => (int) ($data['invoice_discount_value'] ?? 0),
            ]));

            $summary = $this->syncPurchaseItems($purchase, $data, (int) $data['warehouse_id']);
            $purchase->update($this->purchaseTablePayload($summary));

            app(SupplierLedgerService::class)->syncPurchaseCredit($purchase->fresh());
        });

        return redirect()->route('purchases.index')->with('success', 'سند خرید با موفقیت ویرایش شد.');
    }

    public function destroy(Purchase $purchase)
    {
        DB::transaction(function () use ($purchase) {
            app(SupplierLedgerService::class)->voidPurchaseCredit($purchase);
            $this->rollbackPurchase($purchase);
            $purchase->delete();
        });

        return redirect()->route('purchases.index')->with('success', 'سند خرید با موفقیت حذف شد.');
    }


    private function purchaseTablePayload(array $payload): array
    {
        return collect($payload)
            ->filter(fn ($value, string $column) => $this->purchaseHasColumn($column))
            ->all();
    }

    private function purchaseHasColumn(string $column): bool
    {
        static $columns = [];

        if (! array_key_exists($column, $columns)) {
            $columns[$column] = Schema::hasColumn('purchases', $column);
        }

        return $columns[$column];
    }


    private function productVariantHasColumn(string $column): bool
    {
        static $columns = [];

        if (! array_key_exists($column, $columns)) {
            $columns[$column] = Schema::hasColumn('product_variants', $column);
        }

        return $columns[$column];
    }

    private function canLoadVariantColor(): bool
    {
        return $this->productVariantHasColumn('color_id') && Schema::hasTable('colors');
    }

    private function productVariantRelations(): array
    {
        $relations = ['variants.modelList:id,model_name,code'];

        if ($this->canLoadVariantColor()) {
            $relations[] = 'variants.color:id,name,code';
        }

        return $relations;
    }

    private function variantRelations(): array
    {
        $relations = ['modelList:id,model_name,code'];

        if ($this->canLoadVariantColor()) {
            $relations[] = 'color:id,name,code';
        }

        return $relations;
    }

    private function purchaseVariantSelectColumns(): array
    {
        $columns = [
            'product_variants.id',
            'product_variants.product_id',
            'product_variants.model_list_id',
            'product_variants.variant_code',
            'product_variants.variant_name',
            'product_variants.variety_name',
            'product_variants.variety_code',
            'product_variants.sell_price',
            'product_variants.buy_price',
            'product_variants.stock',
            'product_variants.reserved',
            'product_variants.is_active',
            'model_lists.model_name as model_name',
            'model_lists.code as model_code',
        ];


        if ($this->productVariantHasColumn('color_id')) {
            $columns[] = 'product_variants.color_id';
        }

        return $columns;
    }

    private function variantSelectColumns(): array
    {
        $columns = [
            'id',
            'product_id',
            'model_list_id',
            'variant_name',
            'variant_code',
            'variety_name',
            'variety_code',
            'sell_price',
            'buy_price',
            'stock',
            'reserved',
            'is_active',
        ];


        if ($this->productVariantHasColumn('color_id')) {
            $columns[] = 'color_id';
        }

        return $columns;
    }

    private function variantPayload(ProductVariant $variant): array
    {
        return [
            'id' => (int) $variant->id,
            'product_id' => (int) $variant->product_id,
            'model_list_id' => $variant->model_list_id ? (int) $variant->model_list_id : 0,
            'name' => (string) ($variant->variant_name ?? ''),
            'title' => (string) ($variant->variant_name ?: ($variant->variety_name ?: 'تنوع اصلی')),
            'model_name' => (string) ($variant->modelList?->model_name ?? ''),
            'model_code' => (string) ($variant->modelList?->code ?? ''),
            'variety_name' => (string) ($variant->variety_name ?? ''),
            'variety_code' => (string) ($variant->variety_code ?? ''),
            'code' => (string) ($variant->variant_code ?? ''),
            'variant_code' => (string) ($variant->variant_code ?? ''),
            'sku' => (string) ($variant->variant_code ?? ''),
            'display_code' => (string) ($variant->variant_code ?: '-'),
            'barcode' => (string) ($variant->variant_code ?? ''),
            'color_name' => (string) ($variant->color?->name ?? ''),
            'color_code' => (string) ($variant->color?->code ?? ''),
            'central_stock' => (int) ($variant->stock ?? 0),
            'stock' => (int) ($variant->stock ?? 0),
            'reserved' => (int) ($variant->reserved ?? 0),
            'buy_price' => \App\Support\Currency::toRial($variant->buy_price ?? 0),
            'sell_price' => \App\Support\Currency::toRial($variant->sell_price ?? 0),
            'is_active' => (bool) ($variant->is_active ?? true),
        ];
    }

    private function discountTypeLabel(?string $type): string
    {
        return match ($type) {
            'amount' => 'مبلغی',
            'percent' => 'درصدی',
            default => '',
        };
    }

    private function resolveNote(Request $request): ?string
    {
        $candidateKeys = ['note', 'notes', 'description'];

        foreach ($candidateKeys as $key) {
            if ($request->has($key) && trim((string) $request->input($key)) !== '') {
                return trim((string) $request->input($key));
            }
        }

        foreach ($candidateKeys as $key) {
            if ($request->has($key)) {
                return null;
            }
        }

        return null;
    }

    private function validatePayload(Request $request, bool $allowZeroExistingItems = false): array
    {
        $invoiceDiscountType = $request->input('invoice_discount_type', $request->input('discount_type'));
        $invoiceDiscountRaw = $request->input('invoice_discount_value', $request->input('discount_value'));
        $invoiceDiscountValue = $this->filledNumber($invoiceDiscountRaw) ? $this->normalizeNumber($invoiceDiscountRaw) : null;
        $note = $this->resolveNote($request);

        $items = collect((array) $request->input('items', []))
            ->map(function ($item) {
                if (array_key_exists('quantity', $item)) {
                    $item['quantity'] = $this->normalizeNumber($item['quantity']);
                }

                if (array_key_exists('qty', $item)) {
                    $item['qty'] = $this->normalizeNumber($item['qty']);
                }

                if (array_key_exists('discount_value', $item) && $this->filledNumber($item['discount_value'])) {
                    $item['discount_value'] = $this->normalizeNumber($item['discount_value']);
                }

                return $item;
            })
            ->filter(function ($item) use ($allowZeroExistingItems) {
                $quantity = $this->normalizeNumber($item['quantity'] ?? $item['qty'] ?? 0);

                return $quantity > 0 || ($allowZeroExistingItems && !empty($item['id']));
            })
            ->values()
            ->all();

        $request->merge([
            'supplier_id' => $request->filled('supplier_id')
                ? (string) $request->input('supplier_id')
                : $request->input('supplier_id'),
            'invoice_discount_type' => $invoiceDiscountType,
            'invoice_discount_value' => $invoiceDiscountValue,
            'note' => $note,
            'warehouse_id' => WarehouseStockService::centralWarehouseId(),
            'items' => $items,
        ]);

        $data = $request->validate([
            'supplier_id' => ['required', 'string', 'max:100'],
            'warehouse_id' => ['required', 'exists:warehouses,id'],
            'purchased_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:1000'],

            'invoice_discount_type' => ['nullable', 'in:amount,percent,none'],
            'invoice_discount_value' => ['nullable', 'integer', 'min:0'],

            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.id' => ['nullable', 'integer', 'exists:purchase_items,id'],
            'items.*.variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'items.*.product_variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],

            'items.*.qty' => ['nullable', 'integer', 'min:1'],
            'items.*.quantity' => ['nullable', 'integer', 'min:' . ($allowZeroExistingItems ? '0' : '1')],

            'items.*.buy_price' => ['nullable'],
            'items.*.sell_price' => ['nullable'],
            'items.*.sale_price' => ['nullable'],
            'items.*.product_buy_price' => ['nullable'],
            'items.*.product_sell_price' => ['nullable'],
            'items.*.product_sale_price' => ['nullable'],

            'items.*.discount_type' => ['nullable', 'in:amount,percent'],
            'items.*.discount_value' => ['nullable', 'integer', 'min:0'],
        ], [
            'items.required' => 'حداقل یک تنوع را برای خرید انتخاب کنید.',
            'items.min' => 'حداقل یک تنوع را برای خرید انتخاب کنید.',
        ]);

        $data['supplier_id'] = $this->resolvePurchaseSupplierId((string) $data['supplier_id']);

        $data['items'] = array_values(array_map(function ($item, $index) use ($allowZeroExistingItems) {
            $qty = $this->normalizeNumber($item['quantity'] ?? $item['qty'] ?? 0);
            $variantId = $item['variant_id'] ?? $item['product_variant_id'] ?? null;
            $item['variant_id'] = $variantId;

            $buyRaw = $item['buy_price'] ?? null;
            $sellRaw = $item['sell_price'] ?? ($item['sale_price'] ?? null);
            $productBuyRaw = $item['product_buy_price'] ?? null;
            $productSellRaw = $item['product_sell_price'] ?? ($item['product_sale_price'] ?? null);

            $buyPrice = $this->normalizeMoneyValue($buyRaw);
            $sellPrice = $this->normalizeMoneyValue($sellRaw);
            $productBuyPrice = $this->normalizeMoneyValue($productBuyRaw);
            $productSellPrice = $this->normalizeMoneyValue($productSellRaw);

            $buyPrice = $buyPrice ?? $productBuyPrice;
            $sellPrice = $sellPrice ?? $productSellPrice;

            $item['quantity'] = $allowZeroExistingItems ? max(0, $qty) : max(1, $qty);
            $isDeletionRow = $allowZeroExistingItems && $item['quantity'] === 0 && !empty($item['id']);

            if (! $isDeletionRow) {
                $variantLabel = $this->purchaseItemVariantLabel($item);
                $messages = [];

                if ($buyPrice === null) {
                    $messages["items.{$index}.buy_price"] = "برای تنوع {$variantLabel} قیمت خرید را وارد کنید.";
                }

                if ($sellPrice === null) {
                    $messages["items.{$index}.sell_price"] = "برای تنوع {$variantLabel} قیمت فروش را وارد کنید.";
                }

                if ($messages !== []) {
                    $this->logInvalidPurchaseItemPrice($index, $item, $buyRaw, $sellRaw, $productBuyRaw, $productSellRaw);

                    throw ValidationException::withMessages($messages);
                }
            }

            $item['buy_price'] = $isDeletionRow ? 0 : max(0, (int) $buyPrice);
            $item['sell_price'] = $isDeletionRow ? 0 : max(0, (int) $sellPrice);
            $item['product_buy_price'] = $productBuyPrice === null ? null : max(0, (int) $productBuyPrice);
            $item['product_sell_price'] = $productSellPrice === null ? null : max(0, (int) $productSellPrice);
            $item['discount_value'] = $this->filledNumber($item['discount_value'] ?? null)
                ? max(0, $this->normalizeNumber($item['discount_value']))
                : 0;

            return $item;
        }, $data['items'], array_keys($data['items'])));

        if (($data['invoice_discount_type'] ?? null) === 'none') {
            $data['invoice_discount_type'] = null;
            $data['invoice_discount_value'] = 0;
        }

        foreach ($data['items'] as $index => $item) {
            $product = Product::find((int) $item['product_id']);
            $isValidVariant = $product
                ? app(ProductVariantStructureService::class)->applyValidConstraints(ProductVariant::query(), $product)->whereKey($item['variant_id'])->exists()
                : false;

            if (!$isValidVariant) {
                throw ValidationException::withMessages([
                    "items.{$index}.variant_id" => 'تنوع انتخاب‌شده متعلق به این کالا نیست.',
                ]);
            }
        }

        $seenVariants = [];
        foreach ($data['items'] as $index => $item) {
            $key = ((int) $item['product_id']) . ':' . ((int) $item['variant_id']);
            if (isset($seenVariants[$key])) {
                abort(422, 'مدل/طرح تکراری در ردیف ' . ($index + 1) . ' مجاز نیست. هر مدل برای هر کالا فقط یک بار قابل ثبت است.');
            }
            $seenVariants[$key] = true;
        }

        return $data;
    }



    private function purchaseItemVariantLabel(array $item): string
    {
        $label = trim((string) ($item['variant_name'] ?? ''));

        if ($label !== '') {
            return $label;
        }

        $variantId = (int) ($item['variant_id'] ?? $item['product_variant_id'] ?? 0);
        if ($variantId > 0) {
            $variant = ProductVariant::query()->find($variantId, ['variant_name', 'variety_name', 'variant_code']);
            if ($variant) {
                $parts = array_filter([
                    trim((string) $variant->variant_name),
                    trim((string) $variant->variety_name),
                    trim((string) $variant->variant_code),
                ]);

                if ($parts !== []) {
                    return implode(' / ', array_unique($parts));
                }
            }
        }

        return 'انتخاب‌شده';
    }


    private function logInvalidPurchaseItemPrice(int $index, array $item, mixed $buyRaw, mixed $sellRaw, mixed $productBuyRaw, mixed $productSellRaw): void
    {
        if (! config('app.debug')) {
            return;
        }

        \Log::debug('Purchase item price before validation', [
            'index' => $index,
            'variant_id' => $item['variant_id'] ?? $item['product_variant_id'] ?? null,
            'quantity' => $item['quantity'] ?? $item['qty'] ?? null,
            'buy_price_raw' => $buyRaw,
            'sell_price_raw' => $sellRaw,
            'sale_price_raw' => $item['sale_price'] ?? null,
            'product_buy_price_raw' => $productBuyRaw,
            'product_sell_price_raw' => $productSellRaw,
            'product_sale_price_raw' => $item['product_sale_price'] ?? null,
            'row_keys' => array_keys($item),
        ]);
    }

    private function filledNumber(mixed $value): bool
    {
        return trim((string) ($value ?? '')) !== '';
    }

    private function normalizeMoneyValue(mixed $value): ?int
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim($this->normalizePriceDigits($value));

        if ($normalized === '') {
            return null;
        }

        $normalized = str_replace([',', '،', '٬', ' ', "\xc2\xa0", "\u{200c}"], '', $normalized);
        $normalized = preg_replace('/(?<=\d)\.(?=\d{3}(\D|$))/', '', $normalized);

        if (! is_numeric($normalized)) {
            return null;
        }

        return (int) $normalized;
    }

    private function normalizeNumber(mixed $value): int
    {
        $normalized = $this->normalizePriceDigits($value);

        return (int) preg_replace('/[^\d]/', '', $normalized);
    }

    private function normalizePriceDigits(mixed $value): string
    {
        return strtr((string) ($value ?? ''), [
            '۰' => '0',
            '۱' => '1',
            '۲' => '2',
            '۳' => '3',
            '۴' => '4',
            '۵' => '5',
            '۶' => '6',
            '۷' => '7',
            '۸' => '8',
            '۹' => '9',
            '٠' => '0',
            '١' => '1',
            '٢' => '2',
            '٣' => '3',
            '٤' => '4',
            '٥' => '5',
            '٦' => '6',
            '٧' => '7',
            '٨' => '8',
            '٩' => '9',
        ]);
    }

    private function purchaseSupplierOptions()
    {
        $suppliers = Supplier::query()
            ->orderBy('name')
            ->get(['id', 'name', 'phone'])
            ->map(function (Supplier $supplier) {
                $supplier->purchase_option_value = (string) $supplier->id;
                $supplier->purchase_option_label = $supplier->name;
                $supplier->purchase_option_source = 'supplier';

                return $supplier;
            });

        $supplierPhones = $suppliers
            ->pluck('phone')
            ->filter()
            ->map(fn ($phone) => preg_replace('/\D+/', '', (string) $phone))
            ->filter()
            ->flip();

        $customerOptions = Customer::query()
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'mobile'])
            ->filter(function (Customer $customer) use ($supplierPhones) {
                $phone = preg_replace('/\D+/', '', (string) $customer->mobile);

                return $phone === '' || ! $supplierPhones->has($phone);
            })
            ->map(function (Customer $customer) {
                $name = $customer->display_name !== '' ? $customer->display_name : ('مشتری #' . $customer->id);

                return (object) [
                    'id' => $customer->id,
                    'name' => $name,
                    'phone' => $customer->mobile,
                    'purchase_option_value' => 'customer:' . $customer->id,
                    'purchase_option_label' => $name . ' (مشتری)',
                    'purchase_option_source' => 'customer',
                ];
            });

        return $suppliers->concat($customerOptions)->sortBy('name')->values();
    }

    private function resolvePurchaseSupplierId(string $value): int
    {
        if (preg_match('/^customer:(\d+)$/', $value, $matches)) {
            $customer = Customer::query()->findOrFail((int) $matches[1]);
            $name = $customer->display_name !== '' ? $customer->display_name : ('مشتری #' . $customer->id);

            $attributes = [
                'name' => $name,
                'address' => $customer->address,
                'postal_code' => $customer->postal_code,
                'additional_notes' => 'ایجاد خودکار از مشتری برای ثبت خرید کالا',
            ];

            if (filled($customer->mobile)) {
                $supplier = Supplier::query()->firstOrCreate(
                    ['phone' => $customer->mobile],
                    $attributes
                );
            } else {
                $supplier = Supplier::query()->create($attributes);
            }

            return (int) $supplier->id;
        }

        if (ctype_digit($value) && Supplier::query()->whereKey((int) $value)->exists()) {
            return (int) $value;
        }

        throw ValidationException::withMessages([
            'supplier_id' => 'تامین‌کننده یا مشتری انتخاب‌شده معتبر نیست.',
        ]);
    }

    private function applyItems(Purchase $purchase, array $data, int $warehouseId): array
    {
        $subtotalAmount = 0;
        $itemsDiscountTotal = 0;

        foreach ($data['items'] as $item) {
            $quantity = (int) $item['quantity'];
            $buyPrice = (int) $item['buy_price'];
            $sellPrice = (int) $item['sell_price'];

            $lineSubtotal = $quantity * $buyPrice;

            $itemDiscountType = $item['discount_type'] ?? null;
            $itemDiscountValue = (int) ($item['discount_value'] ?? 0);
            $itemDiscountAmount = $this->calculateDiscount($lineSubtotal, $itemDiscountType, $itemDiscountValue);
            $lineTotal = max(0, $lineSubtotal - $itemDiscountAmount);

            $product = Product::whereKey($item['product_id'])->lockForUpdate()->firstOrFail();

            $variant = ProductVariant::where('product_id', $product->id)
                ->whereKey($item['variant_id'])
                ->lockForUpdate()
                ->firstOrFail();

            $before = (int) $variant->stock;
            $after = $before + $quantity;

            $variantUpdates = ['stock' => $after];
            if ($buyPrice > 0) {
                $variantUpdates['buy_price'] = $buyPrice;
            }
            if ($sellPrice > 0) {
                $variantUpdates['sell_price'] = $sellPrice;
            }
            $variant->update($variantUpdates);

            $this->recalcProductSummary($product);

            StockMovement::create([
                'product_id' => $product->id,
                'product_variant_id' => $variant->id,
                'warehouse_id' => $warehouseId,
                'user_id' => auth()->id(),
                'type' => StockMovement::TYPE_IN,
                'reason' => StockMovement::REASON_PURCHASE,
                'quantity' => $quantity,
                'stock_before' => $before,
                'stock_after' => $after,
                'reference' => 'PUR-' . $purchase->id,
                'note' => 'ثبت/ویرایش خرید کالا - مدل: ' . $variant->variant_name,
            ]);

            WarehouseStockService::change($warehouseId, $product->id, $quantity, (int) $variant->id);

            $purchase->items()->create([
                'product_id' => $product->id,
                'product_variant_id' => $variant->id,
                'product_name' => $product->name,
                'product_code' => $product->code,
                'variant_name' => $variant->variant_name,
                'quantity' => $quantity,
                'buy_price' => $buyPrice,
                'sell_price' => $sellPrice,
                'line_subtotal' => $lineSubtotal,
                'discount_type' => $itemDiscountType,
                'discount_value' => $itemDiscountValue,
                'discount_amount' => $itemDiscountAmount,
                'line_total' => $lineTotal,
            ]);

            $subtotalAmount += $lineSubtotal;
            $itemsDiscountTotal += $itemDiscountAmount;
        }

        $baseAfterItemDiscount = max(0, $subtotalAmount - $itemsDiscountTotal);

        $invoiceDiscountType = $data['invoice_discount_type'] ?? null;
        $invoiceDiscountValue = (int) ($data['invoice_discount_value'] ?? 0);

        $invoiceDiscountAmount = $this->calculateDiscount($baseAfterItemDiscount, $invoiceDiscountType, $invoiceDiscountValue);

        $totalDiscount = $itemsDiscountTotal + $invoiceDiscountAmount;
        $totalAmount = max(0, $subtotalAmount - $totalDiscount);

        return [
            'subtotal_amount' => $subtotalAmount,
            'discount_type' => $invoiceDiscountType,
            'discount_value' => $invoiceDiscountValue,
            'total_discount' => $totalDiscount,
            'total_amount' => $totalAmount,
        ];
    }

    private function syncPurchaseItems(Purchase $purchase, array $data, int $warehouseId): array
    {
        $purchase->load('items');

        $oldItemsById = $purchase->items->keyBy('id');
        $oldItemsByVariant = $purchase->items
            ->filter(fn ($item) => (int) $item->product_variant_id > 0)
            ->keyBy(fn ($item) => (int) $item->product_variant_id);

        $requestItems = collect($data['items'])
            ->map(function (array $item) {
                $item['id'] = isset($item['id']) ? (int) $item['id'] : null;
                $item['variant_id'] = (int) ($item['variant_id'] ?? $item['product_variant_id'] ?? 0);
                $item['product_id'] = (int) $item['product_id'];
                $item['quantity'] = (int) $item['quantity'];

                return $item;
            })
            ->filter(fn ($item) => $item['variant_id'] > 0)
            ->values();

        $variantIds = $requestItems->pluck('variant_id')
            ->merge($purchase->items->pluck('product_variant_id'))
            ->map(fn ($id) => (int) $id)
            ->filter(fn ($id) => $id > 0)
            ->unique()
            ->sort()
            ->values();

        $variants = ProductVariant::query()->whereIn('id', $variantIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
        $productIds = $variants->pluck('product_id')->merge($requestItems->pluck('product_id'))->map(fn ($id) => (int) $id)->filter()->unique()->sort()->values();
        $products = Product::query()->whereIn('id', $productIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');

        $affectedProductIds = [];
        $handledOldItemIds = [];

        foreach ($requestItems as $newItem) {
            $newVariant = $variants->get($newItem['variant_id']);
            if (!$newVariant) abort(422, 'تنوع انتخاب‌شده معتبر نیست.');

            $oldItem = $newItem['id'] ? $oldItemsById->get($newItem['id']) : null;
            if ($oldItem && (int) $oldItem->purchase_id !== (int) $purchase->id) abort(422, 'ردیف خرید انتخاب‌شده متعلق به این سند نیست.');
            if (!$oldItem) $oldItem = $oldItemsByVariant->get($newItem['variant_id']);
            if ($oldItem) $handledOldItemIds[] = (int) $oldItem->id;

            $oldQty = $oldItem ? (int) $oldItem->quantity : 0;
            $newQty = (int) $newItem['quantity'];
            if (!$oldItem && $newQty <= 0) continue;

            $product = $products->get($newItem['product_id']);
            if (!$product || (int) $newVariant->product_id !== (int) $product->id) abort(422, 'کالای انتخاب‌شده برای تنوع معتبر نیست.');

            $oldVariantId = $oldItem ? (int) $oldItem->product_variant_id : 0;
            $newVariantId = (int) $newVariant->id;
            $variantChanged = $oldItem && $oldVariantId !== $newVariantId;

            if ($variantChanged && $oldQty > 0) {
                $oldVariant = $variants->get($oldVariantId) ?: ProductVariant::whereKey($oldVariantId)->lockForUpdate()->first();
                if ($oldVariant && (int) $oldVariant->stock < $oldQty) {
                    throw ValidationException::withMessages(['items' => 'امکان کم کردن یک محصول کمتر از موجودی نیست']);
                }
                if ($oldVariant) {
                    $oldProduct = $products->get((int) $oldVariant->product_id) ?: Product::whereKey((int) $oldVariant->product_id)->lockForUpdate()->first();
                    $before = (int) $oldVariant->stock;
                    $after = $before - $oldQty;
                    $oldVariant->update(['stock' => $after]);
                    if ($oldProduct) {
                        $this->recordPurchaseAdjustmentMovement($purchase, $oldProduct, $oldVariant, $warehouseId, -$oldQty, $before, $after, StockMovement::REASON_PURCHASE_ITEM_CHANGED);
                        WarehouseStockService::change($warehouseId, $oldProduct->id, -$oldQty, (int) $oldVariant->id);
                        $affectedProductIds[] = $oldProduct->id;
                    }
                }

                if ($newQty > 0) {
                    $before = (int) $newVariant->stock;
                    $after = $before + $newQty;
                    $newVariant->update(['stock' => $after]);
                    $this->recordPurchaseAdjustmentMovement($purchase, $product, $newVariant, $warehouseId, $newQty, $before, $after, StockMovement::REASON_PURCHASE_ITEM_CHANGED);
                    WarehouseStockService::change($warehouseId, $product->id, $newQty, (int) $newVariant->id);
                }
            } else {
                $delta = $newQty - $oldQty;
                if ($delta < 0 && (int) $newVariant->stock < abs($delta)) {
                    throw ValidationException::withMessages(['items' => 'امکان کم کردن یک محصول کمتر از موجودی نیست']);
                }
                if ($delta !== 0) {
                    $before = (int) $newVariant->stock;
                    $after = $before + $delta;
                    $newVariant->update(['stock' => $after]);
                    $reason = $oldItem ? StockMovement::REASON_PURCHASE_ITEM_CHANGED : StockMovement::REASON_PURCHASE_ITEM_ADDED;
                    $this->recordPurchaseAdjustmentMovement($purchase, $product, $newVariant, $warehouseId, $delta, $before, $after, $reason);
                    WarehouseStockService::change($warehouseId, $product->id, $delta, (int) $newVariant->id);
                }
            }

            $buyPrice = (int) $newItem['buy_price'];
            $sellPrice = (int) $newItem['sell_price'];
            $lineSubtotal = $newQty * $buyPrice;
            $itemDiscountType = $newItem['discount_type'] ?? null;
            $itemDiscountValue = (int) ($newItem['discount_value'] ?? 0);
            $itemDiscountAmount = $this->calculateDiscount($lineSubtotal, $itemDiscountType, $itemDiscountValue);
            $lineTotal = max(0, $lineSubtotal - $itemDiscountAmount);

            if ($newQty <= 0) { if ($oldItem) $oldItem->delete(); $affectedProductIds[] = $product->id; continue; }

            if ($this->purchaseCanRefreshVariantPrice($purchase, (int) $newVariant->id)) {
                $priceUpdates = [];
                if ($buyPrice > 0) $priceUpdates['buy_price'] = $buyPrice;
                if ($sellPrice > 0) $priceUpdates['sell_price'] = $sellPrice;
                if ($priceUpdates !== []) $newVariant->update($priceUpdates);
            }

            $payload = [
                'product_id' => $product->id, 'product_variant_id' => $newVariant->id, 'product_name' => $product->name,
                'product_code' => $product->code, 'variant_name' => $newVariant->variant_name, 'quantity' => $newQty,
                'buy_price' => $buyPrice, 'sell_price' => $sellPrice, 'line_subtotal' => $lineSubtotal,
                'discount_type' => $itemDiscountType, 'discount_value' => $itemDiscountValue,
                'discount_amount' => $itemDiscountAmount, 'line_total' => $lineTotal,
            ];

            $oldItem ? $oldItem->update($payload) : $purchase->items()->create($payload);
            $affectedProductIds[] = $product->id;
        }

        $removedItems = $purchase->items->filter(fn ($item) => !in_array((int) $item->id, $handledOldItemIds, true));

        foreach ($removedItems as $removedItem) {
            $variantId = (int) $removedItem->product_variant_id;
            if ($variantId <= 0) { $removedItem->delete(); continue; }
            $variant = $variants->get($variantId) ?: ProductVariant::whereKey($variantId)->lockForUpdate()->first();
            if (!$variant) { $removedItem->delete(); continue; }
            $quantity = (int) $removedItem->quantity;
            if ($quantity > 0 && (int) $variant->stock < $quantity) {
                throw ValidationException::withMessages(['items' => 'امکان کم کردن یک محصول کمتر از موجودی نیست']);
            }
            $product = $products->get((int) $variant->product_id) ?: Product::whereKey((int) $variant->product_id)->lockForUpdate()->first();
            if ($product && $quantity > 0) {
                $before = (int) $variant->stock; $after = $before - $quantity; $variant->update(['stock' => $after]);
                $this->recordPurchaseAdjustmentMovement($purchase, $product, $variant, $warehouseId, -$quantity, $before, $after, StockMovement::REASON_PURCHASE_ITEM_REMOVED);
                WarehouseStockService::change($warehouseId, $product->id, -$quantity, (int) $variant->id);
                $affectedProductIds[] = $product->id;
            }
            $removedItem->delete();
        }

        foreach (array_unique($affectedProductIds) as $productId) {
            $product = $products->get((int) $productId) ?: Product::find($productId);
            if ($product) $this->recalcProductSummary($product);
        }

        return $this->purchaseSummaryFromItems($purchase, $data);
    }

    private function recordPurchaseAdjustmentMovement(
        Purchase $purchase,
        Product $product,
        ProductVariant $variant,
        int $warehouseId,
        int $delta,
        int $before,
        int $after,
        string $reason
    ): void {
        if ($delta === 0) {
            return;
        }

        StockMovement::create([
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'warehouse_id' => $warehouseId,
            'user_id' => auth()->id(),
            'type' => $delta > 0 ? StockMovement::TYPE_IN : StockMovement::TYPE_OUT,
            'reason' => $reason,
            'transaction_type' => StockMovement::TRANSACTION_PURCHASE_ADJUSTMENT,
            'quantity' => abs($delta),
            'stock_before' => $before,
            'stock_after' => $after,
            'reference' => 'PUR-ADJ-' . $purchase->id,
            'reference_type' => Purchase::class,
            'reference_id' => $purchase->id,
            'note' => 'اصلاح تعداد خرید کالا - مدل: ' . $variant->variant_name,
        ]);
    }

    private function purchaseSummaryFromItems(Purchase $purchase, array $data): array
    {
        $purchase->load('items');

        $subtotalAmount = (int) $purchase->items->sum('line_subtotal');
        $itemsDiscountTotal = (int) $purchase->items->sum('discount_amount');
        $baseAfterItemDiscount = max(0, $subtotalAmount - $itemsDiscountTotal);

        $invoiceDiscountType = $data['invoice_discount_type'] ?? null;
        $invoiceDiscountValue = (int) ($data['invoice_discount_value'] ?? 0);
        $invoiceDiscountAmount = $this->calculateDiscount($baseAfterItemDiscount, $invoiceDiscountType, $invoiceDiscountValue);

        return [
            'subtotal_amount' => $subtotalAmount,
            'discount_type' => $invoiceDiscountType,
            'discount_value' => $invoiceDiscountValue,
            'total_discount' => $itemsDiscountTotal + $invoiceDiscountAmount,
            'total_amount' => max(0, $subtotalAmount - $itemsDiscountTotal - $invoiceDiscountAmount),
        ];
    }

    private function purchaseCanRefreshVariantPrice(Purchase $purchase, int $variantId): bool
    {
        return PurchaseItem::query()
            ->where('product_variant_id', $variantId)
            ->where('purchase_id', '!=', $purchase->id)
            ->whereHas('purchase', function ($query) use ($purchase) {
                $query->where('purchased_at', '>', $purchase->purchased_at)
                    ->orWhere(function ($nested) use ($purchase) {
                        $nested->where('purchased_at', $purchase->purchased_at)
                            ->where('id', '>', $purchase->id);
                    });
            })
            ->doesntExist();
    }

    private function calculateDiscount(int $baseAmount, ?string $discountType, int $discountValue): int
    {
        if ($baseAmount <= 0 || !$discountType || $discountValue <= 0) return 0;

        if ($discountType === 'percent') {
            $value = min($discountValue, 100);
            return (int) floor($baseAmount * $value / 100);
        }

        return min($discountValue, $baseAmount);
    }

    private function rollbackPurchase(Purchase $purchase): void
    {
        $purchase->load(['items', 'items.product']);

        $warehouseId = (int) ($purchase->warehouse_id ?? 0);
        if ($warehouseId <= 0) $warehouseId = (int) WarehouseStockService::centralWarehouseId();

        $affectedProductIds = [];

        foreach ($purchase->items as $item) {
            if (!$item->product_variant_id) continue;

            $variant = ProductVariant::whereKey($item->product_variant_id)
                ->lockForUpdate()
                ->first();

            if (!$variant) continue;

            if ((int) $variant->stock < (int) $item->quantity) {
                abort(422, 'امکان ویرایش/حذف این سند وجود ندارد؛ موجودی فعلی یکی از مدل‌ها کمتر از مقدار خرید قبلی است.');
            }

            $variant->update([
                'stock' => (int) $variant->stock - (int) $item->quantity,
            ]);

            WarehouseStockService::change($warehouseId, (int) $variant->product_id, -((int) $item->quantity), (int) $variant->id);

            $affectedProductIds[] = $variant->product_id;
        }

        $purchase->items()->delete();

        StockMovement::where('reference', 'PUR-' . $purchase->id)
            ->where('reason', 'purchase')
            ->delete();

        foreach (array_unique($affectedProductIds) as $productId) {
            $product = Product::find($productId);
            if ($product) $this->recalcProductSummary($product);
        }
    }

    private function recalcProductSummary(Product $product): void
    {
        app(ProductVariantStructureService::class)->recalculateProductSummary($product);
    }
}
