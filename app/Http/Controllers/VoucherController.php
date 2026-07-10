<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Models\User;
use App\Exports\SalesReturnsExport;
use App\Models\WarehouseTransfer;
use App\Services\WarehouseStockService;
use App\Support\SalesDocumentTotals;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;
use Maatwebsite\Excel\Facades\Excel;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;

class VoucherController extends Controller
{
    private function sectionTypeMap(): array
    {
        return [
            'return-from-sale' => WarehouseTransfer::TYPE_CUSTOMER_RETURN,
            'scrap' => WarehouseTransfer::TYPE_SCRAP,
            'personnel' => WarehouseTransfer::TYPE_PERSONNEL_ASSET,
            'transfer' => WarehouseTransfer::TYPE_BETWEEN_WAREHOUSES,
            'sale' => WarehouseTransfer::TYPE_SALE,
        ];
    }

    private function resolveSectionType(string $type): string
    {
        $map = $this->sectionTypeMap();
        abort_unless(isset($map[$type]), 404);

        return $map[$type];
    }

    private function returnsWarehouse(): Warehouse
    {
        return Warehouse::firstOrCreate(
            [
                'type' => 'return',
                'name' => 'انبار مرجوعی',
            ],
            [
                'is_active' => true,
            ]
        );
    }

    private function scrapWarehouse(): Warehouse
    {
        $warehouse = Warehouse::query()
            ->where('is_active', true)
            ->where(function ($q) {
                $q->where('type', 'scrap')
                    ->orWhere('name', 'like', '%ضایعات%');
            })
            ->orderBy('id')
            ->first();

        abort_if(!$warehouse, 422, 'انبار ضایعات پیدا نشد. لطفاً یک انبار ضایعات تعریف کن.');

        return $warehouse;
    }

    public function hub()
    {
        return view('vouchers.hub');
    }

    public function salesIndex(Request $request)
    {
        $q = trim((string) $request->get('q', ''));

        $invoices = Invoice::query()
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($qq) use ($q) {
                    $qq->where('uuid', 'like', "%{$q}%")
                        ->orWhere('customer_name', 'like', "%{$q}%")
                        ->orWhere('customer_mobile', 'like', "%{$q}%");
                });
            })
            ->whereIn('status', ['pending_warehouse_approval', 'collecting', 'checking_discrepancy', 'packing', 'shipped'])
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('vouchers.sales.index', compact('invoices', 'q'));
    }

    public function salesEdit(string $uuid)
    {
        $invoice = Invoice::query()
            ->with(['items.product', 'items.variant'])
            ->where('uuid', $uuid)
            ->firstOrFail();

        return view('vouchers.sales.edit', compact('invoice'));
    }

    public function salesUpdate(string $uuid, Request $request)
    {
        $invoice = Invoice::query()
            ->with(['items.product', 'items.variant'])
            ->where('uuid', $uuid)
            ->firstOrFail();

        $data = $request->validate([
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'exists:invoice_items,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.price' => ['required', 'integer', 'min:0'],
        ]);

        DB::transaction(function () use ($invoice, $data) {
            $centralWarehouseId = WarehouseStockService::centralWarehouseId();
            $itemsById = $invoice->items->keyBy('id');

            foreach ($data['items'] as $row) {
                $item = $itemsById[(int) $row['id']] ?? null;
                if (!$item) {
                    abort(422, 'آیتم نامعتبر است.');
                }

                $variantId = (int) ($item->variant_id ?? 0);
                if ($variantId <= 0) {
                    abort(422, 'برای این آیتم فاکتور، مدل/تنوع ثبت نشده است.');
                }

                $newQty = (int) $row['quantity'];
                $newPrice = (int) $row['price'];
                $deltaQty = $newQty - (int) $item->quantity;

                if ($deltaQty !== 0) {
                    WarehouseStockService::change(
                        $centralWarehouseId,
                        (int) $item->product_id,
                        -$deltaQty,
                        $variantId
                    );
                }

                $item->update([
                    'quantity' => $newQty,
                    'price' => $newPrice,
                    'line_total' => $newQty * $newPrice,
                ]);
            }

            $invoice->loadMissing('items');
            $totals = SalesDocumentTotals::calculate($invoice->items, (int) $invoice->discount_amount, (int) $invoice->shipping_price, ['discount_allocation_mode' => $invoice->discount_allocation_mode]);
            $subtotal = $totals['subtotal_before_discount'];
            $total = $totals['grand_total'];
            $invoice->update([
                'subtotal' => $subtotal,
                'total' => $total,
            ]);
        });

        return redirect()->route('vouchers.sales.index')->with('success', '✅ آیتم‌های حواله فروش بروزرسانی شد.');
    }

    public function sectionIndex(string $type)
    {
        $voucherType = $this->resolveSectionType($type);
        $customerId = $requestCustomerId = request()->integer('customer_id');
        $dateFrom = request()->get('date_from');
        $dateTo = request()->get('date_to');
        $returnReason = (string) request()->get('return_reason', '');
        $productId = request()->integer('product_id');
        $variantId = request()->integer('variant_id');
        $customerName = trim((string) request()->get('customer_name', ''));
        $documentNumber = trim((string) request()->get('document_number', ''));
        $returnReasons = WarehouseTransfer::returnReasonOptions();
        $categories = collect();

        if ($voucherType === WarehouseTransfer::TYPE_CUSTOMER_RETURN) {
            $categories = Category::query()
                ->whereNull('parent_id')
                ->orderBy('name')
                ->get(['id', 'name']);
        }

        $vouchersQuery = WarehouseTransfer::query()
            ->with([
                'fromWarehouse',
                'toWarehouse',
                'user',
                'receiverUser',
                'relatedInvoice',
                'customer',
                'items.product',
                'items.variant.modelList',
                'items.variant.color',
            ]);

        if ($voucherType === WarehouseTransfer::TYPE_CUSTOMER_RETURN) {
            $vouchersQuery
                ->withCount('items as returned_items_count')
                ->withSum('items as returned_items_total_amount', 'line_total');
        }

        $vouchers = $vouchersQuery
            ->where('voucher_type', $voucherType)
            ->when(
                $voucherType === WarehouseTransfer::TYPE_CUSTOMER_RETURN && $customerId > 0,
                fn ($q) => $q->where('customer_id', $customerId)
            )
            ->when($voucherType === WarehouseTransfer::TYPE_CUSTOMER_RETURN && $customerName !== '', fn ($q) => $q->whereHas('customer', fn ($c) => $c->where('first_name', 'like', "%{$customerName}%")->orWhere('last_name', 'like', "%{$customerName}%")))
            ->when($voucherType === WarehouseTransfer::TYPE_CUSTOMER_RETURN && $documentNumber !== '', fn ($q) => $q->where(function ($n) use ($documentNumber) { $term = "%{$documentNumber}%"; $n->where('reference', 'like', $term)->orWhere('external_invoice_number', 'like', $term); }))
            ->when($dateFrom, fn ($q) => $q->whereDate('transferred_at', '>=', $dateFrom))
            ->when($dateTo, fn ($q) => $q->whereDate('transferred_at', '<=', $dateTo))
            ->when(
                $voucherType === WarehouseTransfer::TYPE_CUSTOMER_RETURN
                && $returnReason !== ''
                && isset($returnReasons[$returnReason]),
                fn ($q) => $q->where('return_reason', $returnReason)
            )
            ->when($voucherType === WarehouseTransfer::TYPE_CUSTOMER_RETURN && $productId > 0, fn ($q) => $q->whereHas('items', fn ($i) => $i->where('product_id', $productId)))
            ->when($voucherType === WarehouseTransfer::TYPE_CUSTOMER_RETURN && $variantId > 0, fn ($q) => $q->whereHas('items', fn ($i) => $i->where('product_variant_id', $variantId)))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        $view = match ($type) {
            'return-from-sale' => 'vouchers.section',
            'scrap' => 'vouchers.scrap.index',
            'personnel' => 'vouchers.personnel.index',
            'transfer' => 'vouchers.transfer.index',
            'sale' => 'vouchers.sale.index',
            default => 'vouchers.section',
        };

        $salesInvoices = collect();
        if ($type === 'sale') {
            $salesInvoices = Invoice::query()
                ->with(['items.product', 'items.variant'])
                ->whereNotNull('preinvoice_order_id')
                ->when($dateFrom, fn ($q) => $q->whereDate('created_at', '>=', $dateFrom))
                ->when($dateTo, fn ($q) => $q->whereDate('created_at', '<=', $dateTo))
                ->orderByDesc('id')
                ->paginate(20)
                ->withQueryString();
        }

        return view($view, compact(
            'vouchers',
            'salesInvoices',
            'type',
            'voucherType',
            'categories',
            'requestCustomerId',
            'dateFrom',
            'dateTo',
            'returnReason',
            'returnReasons',
            'productId',
            'variantId',
            'customerName',
            'documentNumber'
        ));
    }

    public function salesReturnsExport(Request $request)
    {
        $filters = [
            'product_id' => $request->integer('product_id'),
            'variant_id' => $request->integer('variant_id'),
            'customer_id' => $request->integer('customer_id'),
            'return_reason' => trim((string) $request->query('return_reason', '')),
            'category_id' => $request->integer('category_id'),
            'subcategory_id' => $request->integer('subcategory_id'),
            'date_from' => trim((string) $request->query('date_from', '')),
            'date_to' => trim((string) $request->query('date_to', '')),
            'customer_name' => trim((string) $request->query('customer_name', '')),
            'document_number' => trim((string) $request->query('document_number', '')),
        ];

        $filename = 'sales-returns-' . now()->format('Ymd-His') . '.xlsx';

        return Excel::download(new SalesReturnsExport($filters), $filename);
    }

    public function salesReturnsPdf(Request $request)
    {
        $filters = [
            'product_id' => $request->integer('product_id'),
            'variant_id' => $request->integer('variant_id'),
            'customer_id' => $request->integer('customer_id'),
            'customer_name' => trim((string) $request->query('customer_name', '')),
            'document_number' => trim((string) $request->query('document_number', '')),
            'return_reason' => trim((string) $request->query('return_reason', '')),
            'category_id' => $request->integer('category_id'),
            'subcategory_id' => $request->integer('subcategory_id'),
            'date_from' => trim((string) $request->query('date_from', '')),
            'date_to' => trim((string) $request->query('date_to', '')),
        ];

        $returns = SalesReturnsExport::baseQuery($filters)->orderBy('transferred_at')->orderBy('id')->get();
        $html = view('vouchers.exports.sales-returns-pdf', ['returns' => $returns, 'generatedAt' => now()])->render();
        $mpdf = new Mpdf(['mode' => 'utf-8', 'format' => 'A4', 'default_font' => 'dejavusans', 'autoScriptToLang' => true, 'autoLangToFont' => true]);
        $mpdf->SetDirectionality('rtl');
        $mpdf->SetTitle('گزارش برگشت از فروش');
        $mpdf->WriteHTML($html);
        $filename = 'sales-returns-' . now()->format('Ymd-His') . '.pdf';

        return response($mpdf->Output($filename, Destination::STRING_RETURN), 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
        ]);
    }


    public function salesReturnsSearchCustomers(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        $customers = Customer::query()
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($subQuery) use ($q) {
                    $subQuery->where('first_name', 'like', "%{$q}%")
                        ->orWhere('last_name', 'like', "%{$q}%")
                        ->orWhere('mobile', 'like', "%{$q}%")
                        ->orWhere('crm_customer_id', 'like', "%{$q}%");
                });
            }, fn ($query) => $query->whereRaw('1 = 0'))
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->limit(20)
            ->get(['id', 'first_name', 'last_name', 'mobile', 'crm_customer_id']);

        return response()->json([
            'results' => $customers->map(function (Customer $customer) {
                $name = trim(($customer->first_name ?? '') . ' ' . ($customer->last_name ?? '')) ?: ('مشتری #' . $customer->id);
                $meta = collect([$customer->crm_customer_id, $customer->mobile])->filter()->implode(' | ');

                return [
                    'id' => (int) $customer->id,
                    'text' => $meta !== '' ? ($name . ' - ' . $meta) : $name,
                ];
            })->values(),
        ]);
    }

    public function salesReturnsCategories()
    {
        return response()->json(Category::query()
            ->whereNull('parent_id')
            ->orderBy('name')
            ->get(['id', 'name']));
    }

    public function salesReturnsSubcategories(Request $request)
    {
        $parentId = (int) $request->query('category_id');

        return response()->json(Category::query()
            ->where('parent_id', $parentId)
            ->orderBy('name')
            ->get(['id', 'name']));
    }

    public function salesReturnsSearchProducts(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $subcategoryId = (int) $request->query('subcategory_id');

        $products = Product::query()
            ->where('category_id', $subcategoryId > 0 ? $subcategoryId : -1)
            ->when($q !== '', fn ($query) => $query->search($q))
            ->orderBy('name')
            ->limit(20)
            ->get(['id', 'name', 'sku', 'code']);

        return response()->json([
            'results' => $products->map(fn (Product $product) => [
                'id' => (int) $product->id,
                'text' => trim($product->name . (($product->code ?: $product->sku) ? ' - ' . ($product->code ?: $product->sku) : '')),
            ])->values(),
        ]);
    }

    public function salesReturnsProductVariants(Product $product)
    {
        $variants = $product->variants()
            ->active()
            ->with(['modelList:id,model_name', 'color:id,name'])
            ->orderBy('variant_name')
            ->get(['id', 'product_id', 'variant_name', 'variant_code', 'model_list_id', 'color_id', 'variety_name']);

        return response()->json($variants->map(fn (ProductVariant $variant) => [
            'id' => (int) $variant->id,
            'text' => collect([
                $variant->variant_name,
                $variant->modelList?->model_name,
                $variant->color?->name,
                $variant->variety_name,
                $variant->variant_code,
            ])->filter()->unique()->implode(' / ') ?: ('تنوع #' . $variant->id),
        ])->values());
    }

    public function sectionCreate(string $type)
    {
        if ($type === 'return-from-sale') {
            return $this->returnCreate();
        }

        return match ($type) {
            'scrap' => $this->scrapCreate(),
            'personnel' => $this->personnelCreate(),
            'transfer' => $this->transferCreate(),
            default => abort(404),
        };
    }

    public function sectionStore(string $type, Request $request)
    {
        if ($type === 'return-from-sale') {
            return $this->returnStore($request);
        }

        $fixedVoucherType = $this->resolveSectionType($type);
        $request->merge(['voucher_type' => $fixedVoucherType]);

        return $this->store($request);
    }

    public function customerInvoices(Customer $customer)
    {
        $invoices = Invoice::query()
            ->withCount('items')
            ->where('customer_id', $customer->id)
            ->whereHas('items')
            ->latest('id')
            ->limit(200)
            ->get([
                'id',
                'uuid',
                'customer_id',
                'customer_name',
                'customer_mobile',
                'created_at',
                'subtotal',
                'total',
                'status',
            ])
            ->map(function (Invoice $invoice) {
                return [
                    'id' => (int) $invoice->id,
                    'uuid' => (string) $invoice->uuid,
                    'customer_name' => (string) ($invoice->customer_name ?? ''),
                    'customer_mobile' => (string) ($invoice->customer_mobile ?? ''),
                    'created_at' => optional($invoice->created_at)->format('Y-m-d H:i'),
                    'invoice_date' => optional($invoice->created_at)->format('Y-m-d H:i'),
                    'subtotal' => (int) ($invoice->subtotal ?? 0),
                    'total' => (int) ($invoice->total ?? 0),
                    'status' => (string) ($invoice->status ?? ''),
                    'items_count' => (int) ($invoice->items_count ?? 0),
                ];
            })
            ->values();

        return response()->json([
            'invoices' => $invoices,
        ]);
    }

    public function returnCreate()
    {
        $customers = Customer::query()
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name', 'mobile']);

        $returnsWarehouse = $this->returnsWarehouse();
        $warehouses = Warehouse::query()
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('type')
                    ->orWhereNotIn('type', ['personnel', 'scrap']);
            })
            ->orderByRaw('id = ? desc', [$returnsWarehouse->id])
            ->orderBy('name')
            ->get(['id', 'name', 'type']);

        $products = Product::query()
            ->with(['variants' => fn ($q) => $q->orderBy('variant_name')])
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'sku', 'barcode', 'category_id', 'price', 'unit']);

        $returnReasons = WarehouseTransfer::returnReasonOptions();
        $categories = Category::query()->orderBy('name')->get(['id', 'name', 'code']);

        return view('vouchers.return-create', compact(
            'customers',
            'warehouses',
            'returnsWarehouse',
            'products',
            'categories',
            'returnReasons'
        ));
    }

    public function returnStore(Request $request)
    {
        $returnType = $request->input('return_type', WarehouseTransfer::RETURN_SOURCE_INTERNAL_INVOICE);

        if ($returnType === WarehouseTransfer::RETURN_SOURCE_EXTERNAL_MANUAL) {
            $items = $this->materializeManualReturnProducts($request->input('items', []));
            $request->merge(['items' => $items]);

            $items = collect($request->input('items', []))
                ->map(function ($item) {
                    if (array_key_exists('unit_price', $item)) {
                        $item['unit_price'] = $this->normalizeMoney($item['unit_price']);
                    }

                    return $item;
                })
                ->all();

            $request->merge(['items' => $items]);
        }

        $rules = [
            'return_type' => ['required', 'in:' . implode(',', [WarehouseTransfer::RETURN_SOURCE_INTERNAL_INVOICE, WarehouseTransfer::RETURN_SOURCE_EXTERNAL_MANUAL])],
            'customer_id' => ['required', 'exists:customers,id'],
            'return_reason' => ['required', 'in:' . implode(',', array_keys(WarehouseTransfer::returnReasonOptions()))],
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:255'],
            'to_warehouse_id' => ['required', 'exists:warehouses,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.variant_id' => ['required', 'exists:product_variants,id'],
            'items.*.invoice_item_id' => ['nullable', 'exists:invoice_items,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ];

        if ($returnType === WarehouseTransfer::RETURN_SOURCE_EXTERNAL_MANUAL) {
            $rules['related_invoice_uuid'] = ['nullable'];
            $rules['external_invoice_number'] = ['required', 'string', 'max:100'];
            $rules['items.*.unit_price'] = ['required', 'integer', 'min:0'];
        } else {
            $rules['related_invoice_uuid'] = ['required', 'exists:invoices,uuid'];
            $rules['external_invoice_number'] = ['nullable', 'string', 'max:100'];
            $rules['items.*.invoice_item_id'] = ['required', 'integer', 'exists:invoice_items,id'];
        }

        $data = $request->validate($rules);
        $returnsWarehouse = $this->returnsWarehouse();
        if (($data['return_reason'] ?? null) === WarehouseTransfer::RETURN_REASON_GOODS_HEALTHY) {
            $data['to_warehouse_id'] = WarehouseStockService::centralWarehouseId();
        }
        $destinationWarehouse = Warehouse::query()
            ->whereKey((int) $data['to_warehouse_id'])
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('type')
                    ->orWhereNotIn('type', ['personnel', 'scrap']);
            })
            ->first();

        abort_if(! $destinationWarehouse, 422, 'انبار مقصد مرجوعی معتبر نیست.');

        $centralWarehouseId = WarehouseStockService::centralWarehouseId();

        if ($data['return_type'] === WarehouseTransfer::RETURN_SOURCE_EXTERNAL_MANUAL) {
            $this->validateManualReturnItems($data['items']);

            $payload = [
                'voucher_type' => WarehouseTransfer::TYPE_CUSTOMER_RETURN,
                'return_type' => WarehouseTransfer::RETURN_SOURCE_EXTERNAL_MANUAL,
                'from_warehouse_id' => $centralWarehouseId,
                'to_warehouse_id' => (int) $destinationWarehouse->id,
                'customer_id' => (int) $data['customer_id'],
                'external_invoice_number' => $data['external_invoice_number'],
                'return_reason' => $data['return_reason'],
                'reference' => $data['reference'] ?? null,
                'note' => $data['note'] ?? null,
                'items' => array_map(fn ($it) => [
                    'category_id' => (int) Product::query()->whereKey((int) $it['product_id'])->value('category_id'),
                    'product_id' => (int) $it['product_id'],
                    'variant_id' => (int) $it['variant_id'],
                    'quantity' => (int) $it['quantity'],
                    'unit_price' => (int) $it['unit_price'],
                ], $data['items']),
            ];
        } else {
            $invoice = Invoice::query()->with('items')->where('uuid', $data['related_invoice_uuid'])->firstOrFail();

            if ((int) $invoice->customer_id !== (int) $data['customer_id']) {
                abort(422, 'فاکتور انتخابی متعلق به مشتری انتخاب‌شده نیست.');
            }

            $this->validateInternalReturnItems($invoice, $data['items']);

            $payload = [
                'voucher_type' => WarehouseTransfer::TYPE_CUSTOMER_RETURN,
                'return_type' => WarehouseTransfer::RETURN_SOURCE_INTERNAL_INVOICE,
                'from_warehouse_id' => $centralWarehouseId,
                'to_warehouse_id' => (int) $destinationWarehouse->id,
                'related_invoice_uuid' => $data['related_invoice_uuid'],
                'return_reason' => $data['return_reason'],
                'reference' => $data['reference'] ?? null,
                'note' => $data['note'] ?? null,
                'items' => array_map(fn ($it) => [
                    'category_id' => (int) Product::query()->whereKey((int) $it['product_id'])->value('category_id'),
                    'invoice_item_id' => (int) $it['invoice_item_id'],
                    'product_id' => (int) $it['product_id'],
                    'variant_id' => (int) $it['variant_id'],
                    'quantity' => (int) $it['quantity'],
                ], $data['items']),
            ];
        }

        DB::transaction(function () use ($payload) {
            $this->createTransfer($payload, now());
        });

        return redirect()->route('vouchers.section.index', 'return-from-sale')->with('success', 'برگشت از فروش ثبت شد.');
    }

    private function returnEdit(WarehouseTransfer $voucher)
    {
        $voucher->load(['items.product.variants', 'items.variant', 'relatedInvoice', 'customer']);
        $customers = Customer::query()->orderBy('first_name')->orderBy('last_name')->get(['id', 'first_name', 'last_name', 'mobile']);
        $returnsWarehouse = $this->returnsWarehouse();
        $warehouses = Warehouse::query()
            ->where('is_active', true)
            ->where(function ($q) {
                $q->whereNull('type')->orWhereNotIn('type', ['personnel', 'scrap']);
            })
            ->orderByRaw('id = ? desc', [$returnsWarehouse->id])
            ->orderBy('name')
            ->get(['id', 'name', 'type']);
        $products = Product::query()
            ->with(['variants' => fn ($q) => $q->orderBy('variant_name')])
            ->orderBy('name')
            ->get(['id', 'name', 'code', 'sku', 'barcode', 'short_barcode', 'category_id', 'price', 'sale_retail', 'sale_wholesale', 'unit']);
        $returnReasons = WarehouseTransfer::returnReasonOptions();
        $categories = Category::query()->orderBy('name')->get(['id', 'name', 'code']);

        return view('vouchers.return-create', compact('voucher', 'customers', 'warehouses', 'returnsWarehouse', 'products', 'categories', 'returnReasons'));
    }

    private function returnUpdate(Request $request, WarehouseTransfer $voucher)
    {
        $returnType = $request->input('return_type', WarehouseTransfer::RETURN_SOURCE_INTERNAL_INVOICE);
        if ($returnType === WarehouseTransfer::RETURN_SOURCE_EXTERNAL_MANUAL) {
            $items = $this->materializeManualReturnProducts($request->input('items', []));
            $request->merge(['items' => collect($items)->map(function ($item) {
                if (array_key_exists('unit_price', $item)) {
                    $item['unit_price'] = $this->normalizeMoney($item['unit_price']);
                }
                return $item;
            })->all()]);
        }

        $rules = [
            'return_type' => ['required', 'in:' . implode(',', [WarehouseTransfer::RETURN_SOURCE_INTERNAL_INVOICE, WarehouseTransfer::RETURN_SOURCE_EXTERNAL_MANUAL])],
            'customer_id' => ['required', 'exists:customers,id'],
            'return_reason' => ['required', 'in:' . implode(',', array_keys(WarehouseTransfer::returnReasonOptions()))],
            'note' => ['nullable', 'string', 'max:255'],
            'to_warehouse_id' => ['required', 'exists:warehouses,id'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.variant_id' => ['required', 'exists:product_variants,id'],
            'items.*.invoice_item_id' => ['nullable', 'exists:invoice_items,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ];

        if ($returnType === WarehouseTransfer::RETURN_SOURCE_EXTERNAL_MANUAL) {
            $rules['related_invoice_uuid'] = ['nullable'];
            $rules['external_invoice_number'] = ['required', 'string', 'max:100'];
            $rules['items.*.unit_price'] = ['required', 'integer', 'min:0'];
        } else {
            $rules['related_invoice_uuid'] = ['required', 'exists:invoices,uuid'];
            $rules['external_invoice_number'] = ['nullable', 'string', 'max:100'];
            $rules['items.*.invoice_item_id'] = ['required', 'integer', 'exists:invoice_items,id'];
        }

        $data = $request->validate($rules);
        $data['reference'] = $voucher->reference;
        $data['voucher_type'] = WarehouseTransfer::TYPE_CUSTOMER_RETURN;
        $data['from_warehouse_id'] = WarehouseStockService::centralWarehouseId();

        if ($returnType === WarehouseTransfer::RETURN_SOURCE_INTERNAL_INVOICE) {
            $invoice = Invoice::query()->with('items')->where('uuid', $data['related_invoice_uuid'])->firstOrFail();
            if ((int) $invoice->customer_id !== (int) $data['customer_id']) abort(422, 'فاکتور انتخابی متعلق به مشتری انتخاب‌شده نیست.');
            $this->validateInternalReturnItemsForUpdate($invoice, $data['items'], $voucher);
        } else {
            $this->validateManualReturnItems($data['items']);
        }

        DB::transaction(function () use ($voucher, $data) {
            $this->rollbackTransfer($voucher);
            $voucher->items()->delete();
            $voucher->forceFill([
                'reference' => $data['reference'] ?? $voucher->reference,
                'voucher_type' => WarehouseTransfer::TYPE_CUSTOMER_RETURN,
                'from_warehouse_id' => $data['from_warehouse_id'],
                'to_warehouse_id' => $data['to_warehouse_id'],
            ])->save();
            $this->fillExistingTransfer($voucher, $data, $voucher->transferred_at ?? now());
        });

        return redirect()->route('vouchers.section.index', 'return-from-sale')->with('success', 'برگشت از فروش ویرایش شد.');
    }

    private function validateInternalReturnItemsForUpdate(Invoice $invoice, array $items, WarehouseTransfer $editingVoucher): void
    {
        $invoiceItemsById = $invoice->items->keyBy('id');
        $requestedByInvoiceItem = [];
        $seenInvoiceItemRows = [];

        foreach ($items as $index => $row) {
            $rowNo = $index + 1;
            $invoiceItemId = (int) ($row['invoice_item_id'] ?? 0);
            $invoiceItem = $invoiceItemsById->get($invoiceItemId);
            if (!$invoiceItem) abort(422, 'ردیف ' . $rowNo . ': آیتم انتخابی متعلق به این فاکتور نیست.');
            if ((int) $invoiceItem->product_id !== (int) $row['product_id'] || (int) $invoiceItem->variant_id !== (int) $row['variant_id']) abort(422, 'ردیف ' . $rowNo . ': کالا یا تنوع با آیتم فاکتور مطابقت ندارد.');
            if (isset($seenInvoiceItemRows[$invoiceItemId])) abort(422, 'ردیف ' . $rowNo . ': این آیتم فاکتور تکراری است و فقط یک‌بار می‌تواند ثبت شود.');
            $seenInvoiceItemRows[$invoiceItemId] = true;
            $requestedByInvoiceItem[$invoiceItemId] = ($requestedByInvoiceItem[$invoiceItemId] ?? 0) + (int) $row['quantity'];
        }

        $returnTransfers = WarehouseTransfer::query()
            ->where('voucher_type', WarehouseTransfer::TYPE_CUSTOMER_RETURN)
            ->where('related_invoice_id', $invoice->id)
            ->whereKeyNot($editingVoucher->id)
            ->with('items')
            ->get();

        $alreadyReturnedByInvoiceItem = $returnTransfers->flatMap->items->filter(fn ($item) => !is_null($item->invoice_item_id))->groupBy('invoice_item_id')->map(fn ($items) => (int) $items->sum('quantity'));
        $legacyReturnedByVariant = $returnTransfers->flatMap->items->filter(fn ($item) => is_null($item->invoice_item_id))->groupBy('product_variant_id')->map(fn ($items) => (int) $items->sum('quantity'));

        foreach ($requestedByInvoiceItem as $invoiceItemId => $requestedQty) {
            $invoiceItem = $invoiceItemsById->get((int) $invoiceItemId);
            $alreadyReturned = (int) ($alreadyReturnedByInvoiceItem[$invoiceItemId] ?? 0) + (int) ($legacyReturnedByVariant[(int) $invoiceItem->variant_id] ?? 0);
            $remaining = max((int) $invoiceItem->quantity - $alreadyReturned, 0);
            if ($requestedQty > $remaining) abort(422, 'تعداد برگشتی از تعداد قابل برگشت این آیتم بیشتر است.');
        }
    }

    private function validateManualReturnItems(array $items): void
    {
        $seenVariantRows = [];

        foreach ($items as $index => $row) {
            $validVariant = ProductVariant::query()
                ->whereKey((int) $row['variant_id'])
                ->where('product_id', (int) $row['product_id'])
                ->exists();

            if (!$validVariant) {
                abort(422, 'ردیف ' . ($index + 1) . ': تنوع انتخاب‌شده متعلق به این کالا نیست.');
            }

            $key = ((int) $row['product_id']) . ':' . ((int) $row['variant_id']);
            if (isset($seenVariantRows[$key])) {
                abort(422, 'ردیف ' . ($index + 1) . ': این محصول/تنوع تکراری است و فقط یک‌بار می‌تواند ثبت شود.');
            }
            $seenVariantRows[$key] = true;
        }
    }


    private function materializeManualReturnProducts(array $items): array
    {
        return DB::transaction(function () use ($items) {
            return collect($items)->map(function (array $item) {
                if (($item['product_id'] ?? null) !== '__new__') {
                    return $item;
                }

                $name = trim((string) ($item['new_product_name'] ?? ''));
                $categoryId = (int) ($item['new_category_id'] ?? 0);
                $variantName = trim((string) ($item['new_variant_name'] ?? ''));
                $sellPrice = $this->normalizeMoney($item['new_sell_price'] ?? ($item['unit_price'] ?? 0));
                $buyPrice = $this->normalizeMoney($item['new_buy_price'] ?? 0);

                if ($name === '') {
                    abort(422, 'برای کالای جدید، نام کالا الزامی است.');
                }

                $category = Category::query()->lockForUpdate()->find($categoryId);
                if (!$category) {
                    abort(422, 'برای کالای جدید، دسته‌بندی معتبر انتخاب کنید.');
                }

                $productCode = $this->nextQuickProductCode($category);
                $variantCode = $productCode . '00000';

                $product = Product::query()->create([
                    'category_id' => $category->id,
                    'name' => $name,
                    'sku' => 'RETURN-' . now()->format('YmdHis') . '-' . random_int(1000, 9999),
                    'code' => $productCode,
                    'short_barcode' => substr($productCode, -4),
                    'stock' => 0,
                    'price' => $sellPrice,
                    'sale_retail' => $sellPrice,
                    'buy_retail' => $buyPrice,
                    'is_sellable' => true,
                ]);

                $variant = ProductVariant::query()->create([
                    'product_id' => $product->id,
                    'is_active' => true,
                    'variant_name' => $variantName !== '' ? $variantName : $name,
                    'variety_name' => $variantName !== '' ? $variantName : '—',
                    'variety_code' => '0000',
                    'variant_code' => $variantCode,
                    'sell_price' => $sellPrice,
                    'buy_price' => $buyPrice,
                    'stock' => 0,
                    'reserved' => 0,
                ]);

                $item['product_id'] = $product->id;
                $item['variant_id'] = $variant->id;
                $item['unit_price'] = $sellPrice;

                return $item;
            })->all();
        });
    }

    private function nextQuickProductCode(Category $category): string
    {
        $categoryCode = preg_replace('/\D+/', '', (string) ($category->code ?? ''));
        $categoryCode = str_pad(substr($categoryCode, 0, 2) ?: '99', 2, '0', STR_PAD_LEFT);

        $max = (int) DB::table('products')
            ->lockForUpdate()
            ->selectRaw("MAX(CAST(COALESCE(NULLIF(short_barcode,''), SUBSTRING(code, 3, 4)) AS UNSIGNED)) as mx")
            ->value('mx');

        $seq = str_pad((string) min($max + 1, 9999), 4, '0', STR_PAD_LEFT);

        return $categoryCode . $seq;
    }

    private function normalizeMoney(mixed $value): int
    {
        $normalized = strtr((string) $value, [
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

        $normalized = preg_replace('/[,\x{066C}\x{060C}\s]+/u', '', $normalized) ?? '';

        if ($normalized === '' || !ctype_digit($normalized)) {
            abort(422, 'مبلغ واحد مرجوعی باید عددی و بدون مقدار منفی باشد.');
        }

        return (int) $normalized;
    }

    private function validateInternalReturnItems(Invoice $invoice, array $items): void
    {
        $invoiceItemsById = $invoice->items->keyBy('id');
        $requestedByInvoiceItem = [];
        $seenInvoiceItemRows = [];

        foreach ($items as $index => $row) {
            $rowNo = $index + 1;
            $invoiceItemId = (int) ($row['invoice_item_id'] ?? 0);
            $invoiceItem = $invoiceItemsById->get($invoiceItemId);

            if (!$invoiceItem) {
                abort(422, 'ردیف ' . $rowNo . ': آیتم انتخابی متعلق به این فاکتور نیست.');
            }

            if ((int) $invoiceItem->product_id !== (int) $row['product_id'] || (int) $invoiceItem->variant_id !== (int) $row['variant_id']) {
                abort(422, 'ردیف ' . $rowNo . ': کالا یا تنوع با آیتم فاکتور مطابقت ندارد.');
            }

            $validVariant = ProductVariant::query()
                ->whereKey((int) $row['variant_id'])
                ->where('product_id', (int) $row['product_id'])
                ->exists();

            if (!$validVariant) {
                abort(422, 'ردیف ' . $rowNo . ': تنوع انتخاب‌شده متعلق به این کالا نیست.');
            }

            if (isset($seenInvoiceItemRows[$invoiceItemId])) {
                abort(422, 'ردیف ' . $rowNo . ': این آیتم فاکتور تکراری است و فقط یک‌بار می‌تواند ثبت شود.');
            }

            $seenInvoiceItemRows[$invoiceItemId] = true;
            $requestedByInvoiceItem[$invoiceItemId] = ($requestedByInvoiceItem[$invoiceItemId] ?? 0) + (int) $row['quantity'];
        }

        $returnTransfers = WarehouseTransfer::query()
            ->where('voucher_type', WarehouseTransfer::TYPE_CUSTOMER_RETURN)
            ->where('related_invoice_id', $invoice->id)
            ->with('items')
            ->get();

        $alreadyReturnedByInvoiceItem = $returnTransfers
            ->flatMap->items
            ->filter(fn ($item) => !is_null($item->invoice_item_id))
            ->groupBy('invoice_item_id')
            ->map(fn ($items) => (int) $items->sum('quantity'));

        $legacyReturnedByVariant = $returnTransfers
            ->flatMap->items
            ->filter(fn ($item) => is_null($item->invoice_item_id))
            ->groupBy('product_variant_id')
            ->map(fn ($items) => (int) $items->sum('quantity'));

        foreach ($requestedByInvoiceItem as $invoiceItemId => $requestedQty) {
            $invoiceItem = $invoiceItemsById->get((int) $invoiceItemId);
            $alreadyReturned = (int) ($alreadyReturnedByInvoiceItem[$invoiceItemId] ?? 0)
                + (int) ($legacyReturnedByVariant[(int) $invoiceItem->variant_id] ?? 0);
            $remaining = max((int) $invoiceItem->quantity - $alreadyReturned, 0);

            if ($requestedQty > $remaining) {
                abort(422, 'تعداد برگشتی از تعداد قابل برگشت این آیتم بیشتر است.');
            }
        }
    }

    public function saleDeliveryIndex(Request $request)
    {
        $q = trim((string) $request->get('q', ''));

        $invoices = Invoice::query()
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($qq) use ($q) {
                    $qq->where('uuid', 'like', "%{$q}%")
                        ->orWhere('customer_name', 'like', "%{$q}%")
                        ->orWhere('customer_mobile', 'like', "%{$q}%");
                });
            })
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('vouchers.sale-delivery.index', compact('invoices', 'q'));
    }

    public function saleDeliveryEdit(string $uuid)
    {
        $invoice = Invoice::query()
            ->with(['items.product', 'items.variant'])
            ->where('uuid', $uuid)
            ->firstOrFail();

        return view('vouchers.sale-delivery.edit', compact('invoice'));
    }

    public function saleDeliveryUpdate(string $uuid, Request $request)
    {
        $invoice = Invoice::query()
            ->with(['items.product', 'items.variant'])
            ->where('uuid', $uuid)
            ->firstOrFail();

        $data = $request->validate([
            'status' => ['required', 'in:pending_warehouse_approval,collecting,checking_discrepancy,packing,shipped,not_shipped'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.id' => ['required', 'integer', 'exists:invoice_items,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.price' => ['required', 'integer', 'min:0'],
        ]);

        DB::transaction(function () use ($invoice, $data) {
            $centralWarehouseId = WarehouseStockService::centralWarehouseId();
            $map = collect($data['items'])->keyBy(fn ($it) => (int) $it['id']);

            foreach ($invoice->items as $item) {
                $payload = $map[(int) $item->id] ?? null;
                if (!$payload) {
                    continue;
                }

                $variantId = (int) ($item->variant_id ?? 0);
                if ($variantId <= 0) {
                    abort(422, 'برای یکی از آیتم‌های فاکتور، مدل/تنوع ثبت نشده است.');
                }

                $oldQty = (int) $item->quantity;
                $newQty = (int) $payload['quantity'];
                $delta = $newQty - $oldQty;

                if ($delta !== 0) {
                    $product = Product::query()
                        ->whereKey((int) $item->product_id)
                        ->first();

                    $before = (int) ($product?->stock ?? 0);

                    WarehouseStockService::change(
                        $centralWarehouseId,
                        (int) $item->product_id,
                        -$delta,
                        $variantId
                    );

                    $product?->refresh();
                    $after = (int) ($product?->stock ?? 0);

                    if ($product) {
                        StockMovement::create([
                            'product_id' => $product->id,
                            'user_id' => auth()->id(),
                            'type' => $delta > 0 ? 'out' : 'in',
                            'reason' => 'sale_edit',
                            'quantity' => abs($delta),
                            'stock_before' => $before,
                            'stock_after' => $after,
                            'reference' => $invoice->uuid,
                            'note' => 'اصلاح اقلام حواله فروش کالا - ' . ($item->variant?->variant_name ?? 'تنوع'),
                        ]);
                    }
                }

                $newPrice = (int) $payload['price'];
                $item->update([
                    'quantity' => $newQty,
                    'price' => $newPrice,
                    'line_total' => $newQty * $newPrice,
                ]);
            }

            $invoice->loadMissing('items');
            $totals = SalesDocumentTotals::calculate($invoice->items, (int) $invoice->discount_amount, (int) $invoice->shipping_price, ['discount_allocation_mode' => $invoice->discount_allocation_mode]);
            $subtotal = $totals['subtotal_before_discount'];
            $total = $totals['grand_total'];

            $invoice->update([
                'subtotal' => $subtotal,
                'total' => $total,
                'status' => $data['status'],
            ]);
        });

        return redirect()->route('vouchers.sale-delivery.index')->with('success', 'حواله فروش کالا بروزرسانی شد.');
    }

    public function outputs(Request $request)
    {
        $filters = [
            'voucher_no' => trim((string) $request->query('voucher_no', '')),
            'date_from' => trim((string) $request->query('date_from', '')),
            'date_to' => trim((string) $request->query('date_to', '')),
            'customer_name' => trim((string) $request->query('customer_name', '')),
            'document_number' => trim((string) $request->query('document_number', '')),
            'reason' => trim((string) $request->query('reason', '')),
            'from_warehouse_id' => (int) $request->query('from_warehouse_id', 0),
            'destination' => trim((string) $request->query('destination', '')),
            'user_q' => trim((string) $request->query('user_q', '')),
        ];

        $reasonLabels = collect($this->combinedReasonLabels())
            ->filter(fn ($label, $type) => $this->directionOfType((string) $type) === 'outgoing')
            ->all();

        $query = WarehouseTransfer::query()
            ->with(['fromWarehouse', 'toWarehouse', 'user', 'customer', 'relatedInvoice'])
            ->withCount('items')
            ->withSum('items as total_quantity', 'quantity')
            ->whereIn('voucher_type', array_keys($reasonLabels));

        $query
            ->when($filters['voucher_no'] !== '', function ($q) use ($filters) {
                $voucherNo = $filters['voucher_no'];
                $q->where(function ($inner) use ($voucherNo) {
                    $inner->where('id', (int) $voucherNo)
                        ->orWhere('reference', 'like', "%{$voucherNo}%");
                });
            })
            ->when($filters['date_from'] !== '', fn ($q) => $q->whereDate('transferred_at', '>=', $filters['date_from']))
            ->when($filters['date_to'] !== '', fn ($q) => $q->whereDate('transferred_at', '<=', $filters['date_to']))
            ->when($filters['reason'] !== '', fn ($q) => $q->where('voucher_type', $filters['reason']))
            ->when($filters['from_warehouse_id'] > 0, fn ($q) => $q->where('from_warehouse_id', $filters['from_warehouse_id']))
            ->when($filters['user_q'] !== '', function ($q) use ($filters) {
                $term = $filters['user_q'];
                $q->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$term}%"));
            })
            ->when($filters['destination'] !== '', function ($q) use ($filters) {
                $term = $filters['destination'];
                $q->where(function ($inner) use ($term) {
                    $inner->whereHas('toWarehouse', fn ($w) => $w->where('name', 'like', "%{$term}%"))
                        ->orWhereHas('customer', fn ($c) => $c->where('first_name', 'like', "%{$term}%")->orWhere('last_name', 'like', "%{$term}%"))
                        ->orWhereHas('relatedInvoice', fn ($i) => $i->where('customer_name', 'like', "%{$term}%"))
                        ->orWhere('beneficiary_name', 'like', "%{$term}%");
                });
            });

        $outputs = $query
            ->latest('transferred_at')
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        $outputs->getCollection()->transform(function (WarehouseTransfer $voucher) use ($reasonLabels) {
            $voucher->setAttribute('voucher_no', $voucher->reference ?: ('TR-' . $voucher->id));
            $voucher->setAttribute('reason_label', $reasonLabels[$voucher->voucher_type] ?? $this->humanReasonLabel($voucher->voucher_type));
            $voucher->setAttribute('destination_label', $this->outputDestinationLabel($voucher));
            $voucher->setAttribute('status_label', $this->outputStatusLabel($voucher));

            return $voucher;
        });

        $summary = [
            'count' => (int) $outputs->total(),
            'items' => (int) $outputs->sum(fn ($v) => (int) ($v->items_count ?? 0)),
            'qty' => (int) $outputs->sum(fn ($v) => (int) ($v->total_quantity ?? 0)),
        ];

        $fromWarehouses = Warehouse::query()
            ->where('is_active', true)
            ->orderBy('name')
            ->get(['id', 'name']);

        return view('vouchers.outputs', compact(
            'outputs',
            'filters',
            'reasonLabels',
            'fromWarehouses',
            'summary'
        ));
    }

    public function index(Request $request)
    {
        $filters = [
            'voucher_no' => trim((string) $request->query('voucher_no', '')),
            'date_from' => trim((string) $request->query('date_from', '')),
            'date_to' => trim((string) $request->query('date_to', '')),
            'customer_name' => trim((string) $request->query('customer_name', '')),
            'document_number' => trim((string) $request->query('document_number', '')),
            'reason' => trim((string) $request->query('reason', '')),
            'direction' => trim((string) $request->query('direction', '')),
            'user_q' => trim((string) $request->query('user_q', '')),
        ];

        $reasonLabels = $this->combinedReasonLabels();
        $directionOptions = $this->directionOptions();

        $query = WarehouseTransfer::query()
            ->with(['fromWarehouse', 'toWarehouse', 'user', 'customer'])
            ->withCount('items')
            ->withSum('items as total_quantity', 'quantity')
            ->whereIn('voucher_type', array_keys($reasonLabels));

        $this->applyVoucherFilters($query, $filters);

        $vouchers = $query->latest('transferred_at')->paginate(20)->withQueryString();
        $summary = [
            'count' => (int) $vouchers->total(),
            'items' => (int) $vouchers->sum(fn ($v) => (int) ($v->items_count ?? 0)),
            'qty' => (int) $vouchers->sum(fn ($v) => (int) ($v->total_quantity ?? 0)),
        ];

        return view('vouchers.index', compact(
            'filters',
            'reasonLabels',
            'directionOptions',
            'vouchers',
            'summary'
        ));
    }

    public function show(WarehouseTransfer $voucher)
    {
        $voucher->load(['fromWarehouse', 'toWarehouse', 'user', 'receiverUser', 'customer', 'relatedInvoice', 'items.product', 'items.variant']);

        return view('vouchers.show', [
            'voucher' => $voucher,
            'reasonLabel' => $this->humanReasonLabel($voucher->voucher_type),
            'directionLabel' => $this->directionOptions()[$this->directionOfType($voucher->voucher_type)] ?? '—',
        ]);
    }

    public function create()
    {
        return $this->createWithType();
    }

    private function scrapCreate()
    {
        $products = Product::query()
            ->where('is_sellable', true)
            ->whereHas('variants', fn ($q) => $q->active())
            ->select('id', 'name', 'sku', 'code', 'category_id', 'stock')
            ->orderBy('name')
            ->get();

        $variants = ProductVariant::query()
            ->active()
            ->orderBy('product_id')
            ->orderBy('variant_name')
            ->get([
                'id',
                'product_id',
                'variant_name',
                'variant_code',
                'stock',
                'reserved',
            ]);

        $scrapWarehouse = $this->scrapWarehouse();

        $fromWarehouses = $this->selectableWarehouses()
            ->where('type', '!=', 'personnel')
            ->filter(fn ($w) => (int) $w->id !== (int) $scrapWarehouse->id)
            ->values();

        return view('vouchers.scrap.create', compact(
            'products',
            'variants',
            'fromWarehouses',
            'scrapWarehouse'
        ));
    }

    private function personnelCreate()
    {
        $categories = Category::orderBy('name')->get();
        $products = Product::query()
            ->where('is_sellable', true)
            ->whereHas('variants', fn ($q) => $q->active())
            ->select('id', 'name', 'sku', 'category_id', 'price')
            ->orderBy('name')
            ->get();

        $variants = ProductVariant::query()
            ->active()
            ->leftJoin('model_lists', 'model_lists.id', '=', 'product_variants.model_list_id')
            ->orderBy('product_variants.variant_name')
            ->get([
                'product_variants.id',
                'product_variants.product_id',
                'product_variants.variant_name',
                'product_variants.variant_code',
                'product_variants.variety_code',
                'product_variants.stock',
                'model_lists.model_name as model_name',
            ]);

        $warehouses = $this->selectableWarehouses();
        $fromWarehouses = $warehouses->where('type', '!=', 'personnel')->values();
        $personnelWarehouses = $warehouses->where('type', 'personnel')->whereNotNull('parent_id')->values();

        $receiverUsers = $this->selectableUsersForPersonnel();

        return view('vouchers.personnel.create', compact('categories', 'products', 'variants', 'fromWarehouses', 'personnelWarehouses', 'receiverUsers'));
    }


    private function selectableUsersForPersonnel()
    {
        return User::query()
            ->when(Schema::hasColumn('users', 'is_active'), fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->get(['id', 'name', 'phone', 'email', 'personnel_code']);
    }

    private function activeUserRule()
    {
        $rule = Rule::exists('users', 'id');

        if (Schema::hasColumn('users', 'is_active')) {
            $rule->where('is_active', true);
        }

        return $rule;
    }

    private function transferCreate()
    {
        $categories = Category::orderBy('name')->get();
        $products = Product::query()
            ->where('is_sellable', true)
            ->whereHas('variants', fn ($q) => $q->active())
            ->select('id', 'name', 'sku', 'category_id', 'price')
            ->orderBy('name')
            ->get();

        $variants = ProductVariant::query()
            ->active()
            ->leftJoin('model_lists', 'model_lists.id', '=', 'product_variants.model_list_id')
            ->orderBy('product_variants.variant_name')
            ->get([
                'product_variants.id',
                'product_variants.product_id',
                'product_variants.variant_name',
                'product_variants.variant_code',
                'product_variants.variety_code',
                'product_variants.stock',
                'model_lists.model_name as model_name',
            ]);

        $warehouses = $this->selectableWarehouses()->where('type', '!=', 'personnel')->values();

        return view('vouchers.transfer.create', compact('categories', 'products', 'variants', 'warehouses'));
    }

    private function createWithType(?string $fixedVoucherType = null, ?string $sectionSlug = null)
    {
        $categories = Category::orderBy('name')->get();
        $products = Product::select('id', 'name', 'sku', 'category_id', 'price')->orderBy('name')->get();
        $variants = ProductVariant::query()
            ->leftJoin('model_lists', 'model_lists.id', '=', 'product_variants.model_list_id')
            ->orderBy('product_variants.variant_name')
            ->get([
                'product_variants.id',
                'product_variants.product_id',
                'product_variants.variant_name',
                'product_variants.variant_code',
                'product_variants.variety_code',
                'product_variants.is_active',
                'model_lists.model_name as model_name',
            ]);
        $warehouses = $this->selectableWarehouses();
        $invoices = Invoice::query()->latest('id')->limit(300)->get(['id', 'uuid', 'customer_name']);
        $centralWarehouseId = WarehouseStockService::centralWarehouseId();
        $voucher = null;

        return view('vouchers.create', compact(
            'categories',
            'products',
            'variants',
            'warehouses',
            'voucher',
            'invoices',
            'centralWarehouseId',
            'fixedVoucherType',
            'sectionSlug'
        ));
    }

    public function edit(WarehouseTransfer $voucher)
    {
        if ($voucher->voucher_type === WarehouseTransfer::TYPE_CUSTOMER_RETURN) {
            return $this->returnEdit($voucher);
        }

        $categories = Category::orderBy('name')->get();
        $products = Product::select('id', 'name', 'sku', 'category_id', 'price')->orderBy('name')->get();
        $variants = ProductVariant::query()
            ->leftJoin('model_lists', 'model_lists.id', '=', 'product_variants.model_list_id')
            ->orderBy('product_variants.variant_name')
            ->get([
                'product_variants.id',
                'product_variants.product_id',
                'product_variants.variant_name',
                'product_variants.variant_code',
                'product_variants.variety_code',
                'product_variants.is_active',
                'model_lists.model_name as model_name',
            ]);
        $warehouses = $this->selectableWarehouses();
        $invoices = Invoice::query()->latest('id')->limit(300)->get(['id', 'uuid', 'customer_name']);
        $centralWarehouseId = WarehouseStockService::centralWarehouseId();

        $voucher->load('items.product', 'items.variant', 'relatedInvoice');

        $fixedVoucherType = null;
        $sectionSlug = null;

        return view('vouchers.create', compact(
            'voucher',
            'categories',
            'products',
            'variants',
            'warehouses',
            'invoices',
            'centralWarehouseId',
            'fixedVoucherType',
            'sectionSlug'
        ));
    }

    public function invoiceProducts(Request $request, string $uuid)
    {
        $invoice = Invoice::query()
            ->with(['items.product', 'items.variant'])
            ->where('uuid', $uuid)
            ->firstOrFail();

        $returnTransfers = WarehouseTransfer::query()
            ->where('voucher_type', WarehouseTransfer::TYPE_CUSTOMER_RETURN)
            ->where('related_invoice_id', $invoice->id)
            ->when($request->integer('exclude_voucher_id') > 0, fn ($q) => $q->whereKeyNot($request->integer('exclude_voucher_id')))
            ->with('items')
            ->get();

        $returnedQtyByInvoiceItem = $returnTransfers
            ->flatMap->items
            ->filter(fn ($item) => !is_null($item->invoice_item_id))
            ->groupBy('invoice_item_id')
            ->map(fn ($items) => (int) $items->sum('quantity'));

        $legacyReturnedQtyByVariant = $returnTransfers
            ->flatMap->items
            ->filter(fn ($item) => is_null($item->invoice_item_id))
            ->groupBy('product_variant_id')
            ->map(fn ($items) => (int) $items->sum('quantity'));

        $products = $invoice->items
            ->filter(fn ($item) => (int) ($item->variant_id ?? 0) > 0)
            ->map(function (InvoiceItem $item) use ($returnedQtyByInvoiceItem, $legacyReturnedQtyByVariant) {
                $variantId = (int) ($item->variant_id ?? 0);
                $invoicedQty = (int) $item->quantity;
                $returnedQty = (int) ($returnedQtyByInvoiceItem[(int) $item->id] ?? 0)
                    + (int) ($legacyReturnedQtyByVariant[$variantId] ?? 0);
                $remainingQty = max($invoicedQty - $returnedQty, 0);
                $unitPrice = (int) ($item->price ?? 0);

                return [
                    'invoice_item_id' => (int) $item->id,
                    'product_id' => (int) $item->product_id,
                    'variant_id' => $variantId,
                    'category_id' => (int) ($item->product?->category_id ?? 0),
                    'name' => (string) ($item->product?->name ?? ('#' . (int) $item->product_id)),
                    'product_code' => (string) ($item->product?->code ?? ''),
                    'variant_name' => (string) ($item->variant?->variant_name ?? ''),
                    'variant_code' => (string) ($item->variant?->variant_code ?? ''),
                    'variant_stock' => (int) ($item->variant?->stock ?? 0),
                    'unit' => (string) ($item->product?->unit ?: 'عدد'),
                    'qty' => $invoicedQty,
                    'already_returned_qty' => $returnedQty,
                    'remaining_qty' => $remainingQty,
                    'unit_price' => $unitPrice,
                    'line_total' => $remainingQty * $unitPrice,
                ];
            })
            ->values();

        return response()->json([
            'invoice_uuid' => $invoice->uuid,
            'products' => $products,
        ]);
    }

    public function store(Request $request)
    {
        $data = $this->validateTransfer($request);

        DB::transaction(function () use ($data) {
            $this->createTransfer($data, now());
        });

        return redirect()->route('vouchers.index')->with('success', 'سند حواله ثبت شد.');
    }

    public function update(Request $request, WarehouseTransfer $voucher)
    {
        if ($voucher->voucher_type === WarehouseTransfer::TYPE_CUSTOMER_RETURN) {
            return $this->returnUpdate($request, $voucher);
        }

        $data = $this->validateTransfer($request, $voucher);

        DB::transaction(function () use ($voucher, $data) {
            $this->rollbackTransfer($voucher);
            $voucher->items()->delete();
            $voucher->delete();
            $this->createTransfer($data, $voucher->transferred_at ?? now());
        });

        return redirect()->route('vouchers.index')->with('success', 'سند حواله با موفقیت ویرایش شد.');
    }

    public function destroy(WarehouseTransfer $voucher)
    {
        DB::transaction(function () use ($voucher) {
            $this->rollbackTransfer($voucher);
            $voucher->delete();
        });

        return back()->with('success', 'سند حواله حذف شد.');
    }

    private function validateTransfer(Request $request, ?WarehouseTransfer $editingVoucher = null): array
    {
        $types = implode(',', array_keys(WarehouseTransfer::typeOptions()));
        $returnReasons = implode(',', array_keys(WarehouseTransfer::returnReasonOptions()));

        $data = $request->validate([
            'voucher_type' => ['required', 'in:' . $types],
            'from_warehouse_id' => ['required', 'exists:warehouses,id'],
            'to_warehouse_id' => ['required', 'exists:warehouses,id'],
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:255'],
            'related_invoice_uuid' => ['nullable', 'string', 'exists:invoices,uuid'],
            'beneficiary_name' => ['nullable', 'string', 'max:255'],
            'receiver_user_id' => ['nullable', 'integer', $this->activeUserRule()],
            'return_reason' => ['nullable', 'in:' . $returnReasons],
            'items' => ['required', 'array', 'min:1'],
            'items.*.category_id' => ['required', 'exists:categories,id'],
            'items.*.product_id' => ['required', 'exists:products,id'],
            'items.*.variant_id' => ['required', 'exists:product_variants,id'],
            'items.*.invoice_item_id' => ['nullable', 'exists:invoice_items,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
            'items.*.personnel_asset_code' => ['nullable', 'digits:4'],
        ]);

        $voucherType = (string) ($data['voucher_type'] ?? '');
        $fromWarehouse = Warehouse::findOrFail((int) $data['from_warehouse_id']);
        $toWarehouse = Warehouse::findOrFail((int) $data['to_warehouse_id']);
        $scrapWarehouse = $this->scrapWarehouse();

        if ($voucherType === WarehouseTransfer::TYPE_SCRAP) {
            if ((int) $toWarehouse->id !== (int) $scrapWarehouse->id) {
                abort(422, 'در حواله ضایعات، مقصد باید انبار ضایعات باشد.');
            }

            if ((int) $fromWarehouse->id === (int) $scrapWarehouse->id) {
                abort(422, 'در حواله ضایعات، مبدا نمی‌تواند خود انبار ضایعات باشد.');
            }
        }

        if ($voucherType === WarehouseTransfer::TYPE_PERSONNEL_ASSET && !$toWarehouse->isPersonnelLeaf()) {
            abort(422, 'در حواله اموال پرسنل، مقصد باید فقط یکی از پرسنل تعریف‌شده باشد.');
        }

        if ($voucherType === WarehouseTransfer::TYPE_PERSONNEL_ASSET && empty($data['receiver_user_id'])) {
            abort(422, 'انتخاب تحویل‌گیرنده الزامی است.');
        }

        if (in_array($voucherType, [WarehouseTransfer::TYPE_BETWEEN_WAREHOUSES, WarehouseTransfer::TYPE_SHOWROOM], true)) {
            if ($toWarehouse->isPersonnelWarehouse() || $fromWarehouse->isPersonnelWarehouse()) {
                abort(422, 'در حواله بین انباری/شوروم، انبار پرسنل مجاز نیست.');
            }
        }

        if ($voucherType === WarehouseTransfer::TYPE_CUSTOMER_RETURN && empty($data['related_invoice_uuid'])) {
            abort(422, 'در حواله مرجوعی مشتری، انتخاب فاکتور مشتری الزامی است.');
        }

        $invoiceProductIds = [];
        $invoiceVariantIds = [];
        $invoiceVariantRemaining = [];

        if (!empty($data['related_invoice_uuid'])) {
            $invoice = Invoice::query()->with('items')->where('uuid', $data['related_invoice_uuid'])->firstOrFail();

            $invoiceProductIds = $invoice->items->pluck('product_id')->map(fn ($v) => (int) $v)->unique()->values()->all();
            $invoiceVariantIds = $invoice->items->pluck('variant_id')->map(fn ($v) => (int) $v)->unique()->values()->all();

            $alreadyReturnedQuery = WarehouseTransfer::query()
                ->where('voucher_type', WarehouseTransfer::TYPE_CUSTOMER_RETURN)
                ->where('related_invoice_id', $invoice->id);

            if ($editingVoucher) {
                $alreadyReturnedQuery->whereKeyNot($editingVoucher->id);
            }

            $alreadyReturnedByVariant = $alreadyReturnedQuery
                ->with('items')
                ->get()
                ->flatMap->items
                ->groupBy('product_variant_id')
                ->map(fn ($items) => (int) $items->sum('quantity'));

            $invoicedByVariant = $invoice->items
                ->groupBy('variant_id')
                ->map(fn ($items) => (int) $items->sum('quantity'));

            foreach ($invoicedByVariant as $variantId => $qty) {
                $invoiceVariantRemaining[(int) $variantId] = max(
                    (int) $qty - (int) ($alreadyReturnedByVariant[(int) $variantId] ?? 0),
                    0
                );
            }
        }

        $seenVariants = [];

        foreach ($data['items'] as $index => $item) {
            $rowNo = $index + 1;

            $belongsToCategory = Product::query()
                ->whereKey((int) $item['product_id'])
                ->where('category_id', (int) $item['category_id'])
                ->exists();

            if (!$belongsToCategory) {
                abort(422, 'ردیف ' . $rowNo . ': کالا در دسته‌بندی انتخابی نیست.');
            }

            $variant = ProductVariant::query()
                ->whereKey((int) $item['variant_id'])
                ->where('product_id', (int) $item['product_id'])
                ->first();

            if (!$variant) {
                abort(422, 'ردیف ' . $rowNo . ': تنوع/طرح انتخابی متعلق به کالای انتخاب‌شده نیست.');
            }

            if ($voucherType !== WarehouseTransfer::TYPE_CUSTOMER_RETURN && !(bool) $variant->is_active) {
                abort(422, 'ردیف ' . $rowNo . ': تنوع انتخابی غیرفعال است.');
            }

            if ($voucherType === WarehouseTransfer::TYPE_CUSTOMER_RETURN && !in_array((int) $item['product_id'], $invoiceProductIds, true)) {
                abort(422, 'ردیف ' . $rowNo . ': کالا باید از اقلام همان فاکتور مشتری انتخاب شود.');
            }

            if ($voucherType === WarehouseTransfer::TYPE_CUSTOMER_RETURN && !in_array((int) $item['variant_id'], $invoiceVariantIds, true)) {
                abort(422, 'ردیف ' . $rowNo . ': تنوع باید از اقلام همان فاکتور مشتری انتخاب شود.');
            }

            if ($voucherType !== WarehouseTransfer::TYPE_CUSTOMER_RETURN) {
                $available = WarehouseStockService::available(
                    (int) $data['from_warehouse_id'],
                    (int) $item['product_id'],
                    (int) $item['variant_id']
                );

                if ((int) $item['quantity'] > $available) {
                    abort(422, 'ردیف ' . $rowNo . ': مقدار انتخابی از موجودی این مدل/تنوع در انبار مبدا بیشتر است. موجودی فعلی: ' . $available);
                }
            } else {
                $remaining = (int) ($invoiceVariantRemaining[(int) $item['variant_id']] ?? 0);

                if ((int) $item['quantity'] > $remaining) {
                    abort(422, 'ردیف ' . $rowNo . ': مقدار از سقف مجاز مرجوعی این تنوع بیشتر است. باقیمانده مجاز: ' . $remaining);
                }
            }

            $dupKey = ((int) $item['product_id']) . ':' . ((int) $item['variant_id']);
            if (isset($seenVariants[$dupKey])) {
                abort(422, 'ردیف ' . $rowNo . ': این محصول/تنوع تکراری است و فقط یک‌بار می‌تواند ثبت شود.');
            }
            $seenVariants[$dupKey] = true;
        }

        if (($data['voucher_type'] ?? null) === WarehouseTransfer::TYPE_CUSTOMER_RETURN && empty($data['return_reason'])) {
            abort(422, 'در حواله مرجوعی مشتری، انتخاب علت برگشت الزامی است.');
        }

        return $data;
    }

    private function fillExistingTransfer(WarehouseTransfer $voucher, array $data, $transferredAt): void
    {
        $newTransfer = $this->createTransfer($data, $transferredAt);
        $newTransfer->load('items');
        $attrs = $newTransfer->getAttributes();
        unset($attrs['id']);
        $voucher->forceFill($attrs)->save();
        foreach ($newTransfer->items as $item) {
            $attrs = $item->getAttributes();
            unset($attrs['id']);
            $attrs['warehouse_transfer_id'] = $voucher->id;
            $voucher->items()->create($attrs);
        }
        $newTransfer->items()->delete();
        $newTransfer->delete();
    }

    private function createTransfer(array $data, $transferredAt): WarehouseTransfer
    {
        $toWarehouse = Warehouse::findOrFail($data['to_warehouse_id']);
        $voucherType = (string) $data['voucher_type'];
        $relatedInvoice = null;

        if (!empty($data['related_invoice_uuid'])) {
            $relatedInvoice = Invoice::where('uuid', $data['related_invoice_uuid'])->firstOrFail();
        }

        $receiverUser = null;
        if ($voucherType === WarehouseTransfer::TYPE_PERSONNEL_ASSET && !empty($data['receiver_user_id'])) {
            $receiverUser = User::query()
                ->when(Schema::hasColumn('users', 'is_active'), fn ($q) => $q->where('is_active', true))
                ->findOrFail((int) $data['receiver_user_id']);
        }

        $transfer = WarehouseTransfer::create([
            'reference' => $data['reference'] ?? null,
            'voucher_type' => $voucherType,
            'from_warehouse_id' => $data['from_warehouse_id'],
            'to_warehouse_id' => $data['to_warehouse_id'],
            'related_invoice_id' => $relatedInvoice?->id,
            'return_type' => $data['return_type'] ?? WarehouseTransfer::RETURN_SOURCE_INTERNAL_INVOICE,
            'external_invoice_number' => $data['external_invoice_number'] ?? null,
            'customer_id' => $relatedInvoice?->customer_id ?? ($data['customer_id'] ?? null),
            'beneficiary_name' => $receiverUser?->name ?? ($data['beneficiary_name'] ?? null),
            'receiver_user_id' => $receiverUser?->id,
            'receiver_name_snapshot' => $receiverUser?->name,
            'return_reason' => $data['return_reason'] ?? null,
            'user_id' => auth()->id(),
            'transferred_at' => $transferredAt,
            'total_amount' => 0,
            'note' => $data['note'] ?? null,
        ]);

        $sum = 0;

        foreach ($data['items'] as $item) {
            $product = Product::query()
                ->whereKey((int) $item['product_id'])
                ->firstOrFail();

            $variant = ProductVariant::query()
                ->whereKey((int) ($item['variant_id'] ?? 0))
                ->where('product_id', $product->id)
                ->lockForUpdate()
                ->first();

            if (!$variant) {
                abort(422, 'تنوع انتخابی برای کالا معتبر نیست.');
            }

            if ($voucherType !== WarehouseTransfer::TYPE_CUSTOMER_RETURN && !(bool) $variant->is_active) {
                abort(422, 'تنوع انتخابی غیرفعال است.');
            }

            $qty = (int) $item['quantity'];
            $stockBefore = (int) $product->stock;

            $invoiceItemPrice = null;
            if ($voucherType === WarehouseTransfer::TYPE_CUSTOMER_RETURN && $relatedInvoice) {
                if (!empty($item['invoice_item_id'])) {
                    $invoiceItemPrice = (int) (InvoiceItem::query()
                        ->whereKey((int) $item['invoice_item_id'])
                        ->where('invoice_id', $relatedInvoice->id)
                        ->where('product_id', $product->id)
                        ->where('variant_id', $variant->id)
                        ->value('price') ?? 0);
                } else {
                    $invoiceItemPrice = (int) ($relatedInvoice->items()
                        ->where('product_id', $product->id)
                        ->where('variant_id', $variant->id)
                        ->value('price') ?? 0);
                }
            }

            $unitPrice = in_array($voucherType, [WarehouseTransfer::TYPE_SCRAP, WarehouseTransfer::TYPE_SHOWROOM], true)
                ? 0
                : ($voucherType === WarehouseTransfer::TYPE_CUSTOMER_RETURN
                    ? (array_key_exists('unit_price', $item) ? (int) $item['unit_price'] : (int) $invoiceItemPrice)
                    : (int) ($product->price ?? 0));

            $lineTotal = $qty * $unitPrice;
            $sum += $lineTotal;

            if ($voucherType === WarehouseTransfer::TYPE_CUSTOMER_RETURN) {
                WarehouseStockService::change(
                    (int) $data['to_warehouse_id'],
                    (int) $item['product_id'],
                    $qty,
                    (int) $item['variant_id']
                );

                $movementType = 'in';
                $movementReason = 'return';
                $movementNote = 'ورود کالا به ' . $toWarehouse->name . ' بابت برگشت از فروش'
                    . (!empty($data['external_invoice_number']) ? (' فاکتور سازه‌حساب شماره ' . $data['external_invoice_number']) : '')
                    . ' - ' . ($variant->variant_name ?: 'تنوع');
            } elseif ($voucherType === WarehouseTransfer::TYPE_SCRAP) {
                $available = WarehouseStockService::available(
                    (int) $data['from_warehouse_id'],
                    (int) $item['product_id'],
                    (int) $item['variant_id']
                );

                if ($qty > $available) {
                    abort(422, 'موجودی این مدل/تنوع برای ثبت ضایعات کافی نیست.');
                }

                WarehouseStockService::change(
                    (int) $data['from_warehouse_id'],
                    (int) $item['product_id'],
                    -$qty,
                    (int) $item['variant_id']
                );

                WarehouseStockService::change(
                    (int) $data['to_warehouse_id'],
                    (int) $item['product_id'],
                    $qty,
                    (int) $item['variant_id']
                );

                $movementType = 'out';
                $movementReason = 'transfer';
                $movementNote = 'انتقال به انبار ضایعات - ' . ($variant->variant_name ?: 'تنوع');
            } else {
                $available = WarehouseStockService::available(
                    (int) $data['from_warehouse_id'],
                    (int) $item['product_id'],
                    (int) $item['variant_id']
                );

                if ($qty > $available) {
                    abort(422, 'موجودی این مدل/تنوع در انبار مبدا کافی نیست.');
                }

                WarehouseStockService::change(
                    (int) $data['from_warehouse_id'],
                    (int) $item['product_id'],
                    -$qty,
                    (int) $item['variant_id']
                );

                WarehouseStockService::change(
                    (int) $data['to_warehouse_id'],
                    (int) $item['product_id'],
                    $qty,
                    (int) $item['variant_id']
                );

                $movementType = 'out';
                $movementReason = 'transfer';
                $movementNote = 'انتقال از '
                    . ($transfer->fromWarehouse?->name ?? 'انبار مبدا')
                    . ' به '
                    . ($transfer->toWarehouse?->name ?? 'انبار مقصد')
                    . ' - '
                    . ($variant->variant_name ?: 'تنوع');
            }

            $product->refresh();
            $stockAfter = (int) $product->stock;

            $transfer->items()->create([
                'invoice_item_id' => $item['invoice_item_id'] ?? null,
                'product_id' => $item['product_id'],
                'product_variant_id' => $variant->id,
                'variant_name' => $variant->variant_name,
                'variant_code' => $variant->variant_code,
                'quantity' => $qty,
                'unit_price' => $unitPrice,
                'line_total' => $lineTotal,
                'personnel_asset_code' => $toWarehouse->isPersonnelLeaf() ? ($item['personnel_asset_code'] ?? null) : null,
            ]);

            StockMovement::create([
                'product_id' => $product->id,
                'product_variant_id' => $variant->id,
                'warehouse_id' => $voucherType === WarehouseTransfer::TYPE_CUSTOMER_RETURN ? (int) $data['to_warehouse_id'] : (int) $data['from_warehouse_id'],
                'user_id' => auth()->id(),
                'type' => $movementType,
                'reason' => $movementReason,
                'quantity' => $qty,
                'stock_before' => $stockBefore,
                'stock_after' => $stockAfter,
                'reference' => $transfer->reference ?: ('TR-' . $transfer->id),
                'reference_type' => WarehouseTransfer::class,
                'reference_id' => $transfer->id,
                'note' => $movementNote,
            ]);
        }

        $transfer->update(['total_amount' => $sum]);

        if ($voucherType === WarehouseTransfer::TYPE_CUSTOMER_RETURN && $transfer->customer_id) {
            $ledgerNote = $relatedInvoice
                ? ('بستانکاری بابت برگشت از فروش فاکتور شماره ' . $relatedInvoice->uuid)
                : ('بستانکاری بابت برگشت از فروش دستی - بابت برگشت از فروش فاکتور سازه‌حساب شماره ' . ($data['external_invoice_number'] ?? '—'));

            if (!$relatedInvoice && !empty($data['note'])) {
                $ledgerNote .= ' - ' . $data['note'];
            }

            CustomerLedger::create([
                'customer_id' => $transfer->customer_id,
                'type' => 'credit',
                'amount' => max($sum, 0),
                'reference_type' => WarehouseTransfer::class,
                'reference_id' => $transfer->id,
                'note' => $ledgerNote,
            ]);
        }

        return $transfer;
    }

    private function rollbackTransfer(WarehouseTransfer $transfer): void
    {
        $transfer->load('items', 'relatedInvoice');

        foreach ($transfer->items as $item) {
            $variantId = (int) $item->product_variant_id;

            if ($variantId <= 0) {
                abort(422, 'برای یکی از ردیف‌های این حواله، تنوع کالا ثبت نشده است.');
            }

            if ($transfer->voucher_type === WarehouseTransfer::TYPE_CUSTOMER_RETURN) {
                WarehouseStockService::change(
                    (int) $transfer->to_warehouse_id,
                    (int) $item->product_id,
                    -((int) $item->quantity),
                    $variantId
                );
            } elseif ($transfer->voucher_type === WarehouseTransfer::TYPE_SCRAP) {
                WarehouseStockService::change(
                    (int) $transfer->from_warehouse_id,
                    (int) $item->product_id,
                    (int) $item->quantity,
                    $variantId
                );

                WarehouseStockService::change(
                    (int) $transfer->to_warehouse_id,
                    (int) $item->product_id,
                    -((int) $item->quantity),
                    $variantId
                );
            } else {
                WarehouseStockService::change(
                    (int) $transfer->to_warehouse_id,
                    (int) $item->product_id,
                    -((int) $item->quantity),
                    $variantId
                );

                WarehouseStockService::change(
                    (int) $transfer->from_warehouse_id,
                    (int) $item->product_id,
                    (int) $item->quantity,
                    $variantId
                );
            }
        }

        if ($transfer->voucher_type === WarehouseTransfer::TYPE_CUSTOMER_RETURN && $transfer->customer_id) {
            CustomerLedger::query()
                ->where('reference_type', WarehouseTransfer::class)
                ->where('reference_id', $transfer->id)
                ->where('type', 'credit')
                ->delete();
        }

        $reference = $transfer->reference ?: ('TR-' . $transfer->id);

        StockMovement::whereIn('reason', ['transfer', 'return'])
            ->where('reference', $reference)
            ->delete();
    }

    private function combinedReasonLabels(): array
    {
        return [
            WarehouseTransfer::TYPE_SALE => 'فروش',
            WarehouseTransfer::TYPE_BETWEEN_WAREHOUSES => 'انتقال بین انبارها',
            WarehouseTransfer::TYPE_PERSONNEL_ASSET => 'تحویل به پرسنل',
            WarehouseTransfer::TYPE_SCRAP => 'ضایعات',
            WarehouseTransfer::TYPE_SHOWROOM => 'انتقال به شوروم',
            WarehouseTransfer::TYPE_CUSTOMER_RETURN => 'برگشت از مشتری',
        ];
    }

    private function outputDestinationLabel(WarehouseTransfer $voucher): string
    {
        $customerName = trim((string) ($voucher->customer?->first_name ?? '') . ' ' . (string) ($voucher->customer?->last_name ?? ''));
        $invoiceCustomer = trim((string) ($voucher->relatedInvoice?->customer_name ?? ''));
        $beneficiary = trim((string) ($voucher->beneficiary_name ?? ''));

        return match ($voucher->voucher_type) {
            WarehouseTransfer::TYPE_BETWEEN_WAREHOUSES
                => $voucher->toWarehouse?->name ? ('انبار: ' . $voucher->toWarehouse->name) : 'انبار مقصد نامشخص',

            WarehouseTransfer::TYPE_PERSONNEL_ASSET
                => $beneficiary !== ''
                    ? ('پرسنل: ' . $beneficiary)
                    : ($voucher->toWarehouse?->personnel_name
                        ? ('پرسنل: ' . $voucher->toWarehouse->personnel_name)
                        : ($voucher->toWarehouse?->name ? ('پرسنل: ' . $voucher->toWarehouse->name) : 'پرسنل')),

            WarehouseTransfer::TYPE_SCRAP
                => $voucher->toWarehouse?->name ? ('ضایعات: ' . $voucher->toWarehouse->name) : 'ضایعات',

            WarehouseTransfer::TYPE_SHOWROOM
                => $voucher->toWarehouse?->name ? ('شوروم: ' . $voucher->toWarehouse->name) : 'شوروم',

            WarehouseTransfer::TYPE_SALE
                => $customerName !== ''
                    ? ('مشتری: ' . $customerName)
                    : ($invoiceCustomer !== ''
                        ? ('مشتری: ' . $invoiceCustomer)
                        : ($beneficiary !== '' ? ('مقصد: ' . $beneficiary) : 'مشتری')),

            default
                => $voucher->toWarehouse?->name ?? ($beneficiary !== '' ? $beneficiary : '—'),
        };
    }

    private function outputStatusLabel(WarehouseTransfer $voucher): string
    {
        $status = (string) ($voucher->relatedInvoice?->status ?? '');
        if ($status === '') {
            return '—';
        }

        $labels = [
            'pending_warehouse_approval' => 'در انتظار تایید انبار',
            'collecting' => 'در حال جمع‌آوری',
            'checking_discrepancy' => 'در حال بررسی مغایرت',
            'packing' => 'در حال بسته‌بندی',
            'shipped' => 'ارسال شده',
            'not_shipped' => 'ارسال نشده',
            'draft' => 'پیش‌نویس',
            'finalized' => 'نهایی شده',
            'cancelled' => 'لغو شده',
        ];

        return $labels[$status] ?? $status;
    }

    private function humanReasonLabel(?string $type): string
    {
        $labels = $this->combinedReasonLabels() + WarehouseTransfer::typeOptions();

        return $labels[$type ?? ''] ?? ($type ?: '—');
    }

    private function directionOptions(): array
    {
        return [
            'outgoing' => 'خروجی',
            'incoming' => 'ورودی',
        ];
    }

    private function directionOfType(?string $type): string
    {
        return match ($type) {
            WarehouseTransfer::TYPE_CUSTOMER_RETURN => 'incoming',
            default => 'outgoing',
        };
    }

    private function applyVoucherFilters($query, array $filters): void
    {
        $query
            ->when($filters['voucher_no'] !== '', function ($q) use ($filters) {
                $voucherNo = $filters['voucher_no'];
                $q->where(function ($inner) use ($voucherNo) {
                    $inner->where('id', (int) $voucherNo)
                        ->orWhere('reference', 'like', "%{$voucherNo}%");
                });
            })
            ->when($filters['date_from'] !== '', fn ($q) => $q->whereDate('transferred_at', '>=', $filters['date_from']))
            ->when($filters['date_to'] !== '', fn ($q) => $q->whereDate('transferred_at', '<=', $filters['date_to']))
            ->when($filters['reason'] !== '', fn ($q) => $q->where('voucher_type', $filters['reason']))
            ->when($filters['direction'] !== '', function ($q) use ($filters) {
                $target = $filters['direction'];
                $types = collect(array_keys($this->combinedReasonLabels()))
                    ->filter(fn ($type) => $this->directionOfType($type) === $target)
                    ->values()
                    ->all();
                if (!empty($types)) {
                    $q->whereIn('voucher_type', $types);
                }
            })
            ->when($filters['user_q'] !== '', function ($q) use ($filters) {
                $term = $filters['user_q'];
                $q->whereHas('user', fn ($u) => $u->where('name', 'like', "%{$term}%"));
            });
    }

    private function selectableWarehouses()
    {
        return Warehouse::query()
            ->with('parent')
            ->where('is_active', true)
            ->where(function ($query) {
                $query->where('type', '!=', 'personnel')
                    ->orWhereNotNull('parent_id');
            })
            ->orderByRaw('CASE WHEN parent_id IS NULL THEN 0 ELSE 1 END')
            ->orderBy('parent_id')
            ->orderBy('name')
            ->get();
    }

    private function syncGlobalProductStock(int $productId, int $delta = 0): void
    {
        WarehouseStockService::syncProductStockFromCentral($productId);
    }
}
