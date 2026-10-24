<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\Product;
use App\Models\StockCountDocument;
use App\Exceptions\StockCountFinalizeValidationException;
use App\Services\StockCountDocumentService;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class StocktakeController extends Controller
{
    public function __construct(private readonly StockCountDocumentService $service) {}

    public function index(Request $request)
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:150'], 'document_number' => ['nullable', 'string', 'max:100'],
            'warehouse_id' => ['nullable', 'integer', 'exists:warehouses,id'],
            'status' => ['nullable', Rule::in(['draft', 'finalized', 'cancelled'])],
            'date_from' => ['nullable', 'string', 'max:10'], 'date_to' => ['nullable', 'string', 'max:10'],
        ]);
        $query = StockCountDocument::query()->with(['warehouse','product','creator'])->withCount('items');
        if (! empty($filters['status'])) $query->where('status', $filters['status']);
        if (! empty($filters['warehouse_id'])) $query->where('warehouse_id', $filters['warehouse_id']);
        $normalize = fn ($value) => strtr(trim($value), ['ي'=>'ی','ك'=>'ک','۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9']);
        if (! empty($filters['document_number'])) $query->where('document_number', 'like', '%'.$normalize($filters['document_number']).'%');
        if ($term = $normalize($filters['q'] ?? '')) {
            $term = '%'.$term.'%';
            $query->where(function ($search) use ($term) {
                $search->where('document_number', 'like', $term)
                    ->orWhereRaw("REPLACE(REPLACE(description, 'ي', 'ی'), 'ك', 'ک') LIKE ?", [$term])
                    ->orWhereHas('product', fn ($q) => $q->whereRaw("REPLACE(REPLACE(name, 'ي', 'ی'), 'ك', 'ک') LIKE ?", [$term]))
                    ->orWhereHas('creator', fn ($q) => $q->whereRaw("REPLACE(REPLACE(name, 'ي', 'ی'), 'ك', 'ک') LIKE ?", [$term]));
            });
        }
        $dates = [];
        foreach (['date_from'=>'>=', 'date_to'=>'<='] as $key=>$operator) {
            if (! empty($filters[$key])) {
                $dates[$key] = \App\Support\JalaliDate::toGregorianDate($filters[$key]);
                if (! $dates[$key]) throw \Illuminate\Validation\ValidationException::withMessages([$key=>'تاریخ شمسی معتبر وارد کنید.']);
                $query->whereDate('document_date', $operator, $dates[$key]);
            }
        }
        if (isset($dates['date_from'], $dates['date_to']) && $dates['date_from'] > $dates['date_to']) {
            throw \Illuminate\Validation\ValidationException::withMessages(['date_to'=>'تاریخ پایان باید بعد از تاریخ شروع باشد.']);
        }
        $documents = (clone $query)->latest('id')->paginate(15)->withQueryString();
        // Keep the existing JSON response shape for archive API consumers.
        if ($request->expectsJson()) return response()->json($documents);
        $summary = ['total'=>(clone $query)->count(), 'finalized'=>(clone $query)->where('status','finalized')->count(),
            'increase'=>(int)(clone $query)->sum('total_increase'), 'decrease'=>(int)(clone $query)->sum('total_decrease')];
        $warehouses = \App\Models\Warehouse::query()->orderBy('name')->get(['id','name']);
        return view('stocktake.index', compact('documents','summary','filters','warehouses'));
    }

    public function create()
    {
        return view('stocktake.form', ['mode'=>'create','document'=>null,'centralWarehouse'=>$this->service->centralWarehouse(),'rootCategories'=>Category::query()->whereNull('parent_id')->orderBy('name')->get()]);
    }

    public function store(Request $request)
    {
        $data = $this->validatedPayload($request, false);
        $document = $this->service->createProductDraft($data, (int) auth()->id());
        return redirect()->route('stock-count-documents.edit', $document)->with('success', 'پیش‌نویس سند انبارگردانی محصولی ذخیره شد.');
    }

    public function show(Request $request, StockCountDocument $stockCountDocument)
    {
        $stockCountDocument->load(['warehouse','product','items.variant','creator','updater','finalizer','canceller','history.doer']);
        return $request->expectsJson() ? response()->json($stockCountDocument) : view('stocktake.show', ['document'=>$stockCountDocument]);
    }
    public function view(Request $request, StockCountDocument $stockCountDocument) { return $this->show($request, $stockCountDocument); }

    public function edit(StockCountDocument $stockCountDocument)
    {
        if ($stockCountDocument->status !== 'draft') return redirect()->route('stock-count-documents.view', $stockCountDocument);
        $stockCountDocument->load(['warehouse','product']);
        return view('stocktake.form', ['mode'=>'edit','document'=>$stockCountDocument,'centralWarehouse'=>$this->service->centralWarehouse(),'rootCategories'=>Category::query()->whereNull('parent_id')->orderBy('name')->get()]);
    }

    public function update(Request $request, StockCountDocument $stockCountDocument)
    {
        $data = $this->validatedPayload($request, true);
        $updated = $this->service->updateProductDraft($stockCountDocument, $data, (int) auth()->id());
        return redirect()->route('stock-count-documents.edit', $updated)->with('success', 'پیش‌نویس ذخیره شد.');
    }

    public function finalize(Request $request, StockCountDocument $stockCountDocument)
    {
        $request->validate(['confirm_empty_as_zero'=>['accepted']]);

        try {
            $finalized = $this->service->finalize($stockCountDocument, (int) auth()->id(), true, $this->validatedPayload($request, true));
        } catch (StockCountFinalizeValidationException $exception) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'امکان ثبت نهایی سند وجود ندارد.',
                    'errors' => [
                        'finalize' => ['موجودی واقعی برخی تنوع‌ها کمتر از رزرو فعال است.'],
                    ],
                    'conflicts' => $exception->conflicts(),
                ], 422);
            }

            return redirect()
                ->back()
                ->withInput()
                ->withErrors([
                    'finalize' => 'امکان ثبت نهایی سند وجود ندارد. موارد مشخص‌شده را اصلاح کنید.',
                ], 'stock_count_finalize')
                ->with('stock_count_conflicts', $exception->conflicts());
        }

        return redirect()->route('stock-count-documents.view', $finalized)->with('success', 'سند انبارگردانی محصولی اعمال شد.');
    }

    public function cancel(Request $request, StockCountDocument $stockCountDocument)
    {
        $cancelled = $this->service->cancel($stockCountDocument, (int) auth()->id(), $request->input('cancel_reason'));
        return back()->with('success', 'پیش‌نویس لغو شد.');
    }

    public function subcategories(Request $request)
    {
        $data = $request->validate(['category_id'=>['required','integer','exists:categories,id']]);
        $ids = Category::selfAndDescendantIds((int)$data['category_id']);
        return Category::query()->whereIn('id',$ids)->where('id','<>',(int)$data['category_id'])->orderBy('name')->get(['id','name']);
    }

    public function products(Request $request)
    {
        $data = $request->validate(['category_id'=>['nullable','integer','exists:categories,id'],'subcategory_id'=>['nullable','integer','exists:categories,id'],'q'=>['nullable','string']]);
        $cat = $data['subcategory_id'] ?? $data['category_id'] ?? null;
        $q = Product::query()->orderBy('name')->limit(30);
        if ($cat) $q->whereIn('category_id', Category::selfAndDescendantIds((int)$cat));
        if ($s=$request->input('q')) $q->where(fn($x)=>$x->where('name','like','%'.$s.'%')->orWhere('sku','like','%'.$s.'%')->orWhere('code','like','%'.$s.'%')->orWhere('barcode','like','%'.$s.'%')->orWhere('short_barcode','like','%'.$s.'%'));
        return $q->get(['id','name','sku','code','barcode']);
    }

    public function variants(Request $request, Product $product)
    {
        $data = $request->validate(['page'=>['nullable','integer','min:1'],'limit'=>['nullable','integer','min:1','max:200'],'q'=>['nullable','string'],'document_id'=>['nullable','integer','exists:stock_count_documents,id']]);
        $document = !empty($data['document_id']) ? StockCountDocument::query()->find((int)$data['document_id']) : null;
        return response()->json($this->service->variantsPage($product->id, $document, (int)($data['page'] ?? 1), (int)($data['limit'] ?? 100), $data['q'] ?? null));
    }

    public function systemQuantity(Request $request) { abort(410, 'برای انبارگردانی محصولی از endpoint دریافت تنوع‌ها استفاده کنید.'); }

    private function validatedPayload(Request $request, bool $editing): array
    {
        return $request->validate([
            'product_id' => [$editing ? 'nullable' : 'required', 'integer', 'exists:products,id'],
            'document_date' => ['required', 'date'], 'description' => ['nullable','string','max:2000'],
            'actual_quantities' => ['nullable','array'], 'actual_quantities.*' => ['nullable','integer','min:0'],
            'notes' => ['nullable','array'], 'notes.*' => ['nullable','string','max:1000'],
        ]);
    }
}
