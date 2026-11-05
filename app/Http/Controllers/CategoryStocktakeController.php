<?php

namespace App\Http\Controllers;

use App\Exceptions\StockCountFinalizeValidationException;
use App\Models\{Category, StockCountDocument};
use App\Services\{StockCountDocumentService, StocktakeRequestService};
use App\Support\ReservationSideEffects;
use Illuminate\Http\Request;

final class CategoryStocktakeController extends Controller
{
    public function __construct(private readonly StockCountDocumentService $service) {}

    public function create(Request $request)
    {
        $data = $request->validate(['category_id'=>['nullable','integer','exists:categories,id']]);
        $category = !empty($data['category_id']) ? Category::findOrFail($data['category_id']) : null;
        if ($category) {
            $selectedScope = array_map('intval', Category::selfAndDescendantIds((int) $category->id));
            $drafts = StockCountDocument::query()->where('type', 'category')
                ->where('status', 'draft')->whereNull('superseded_by_id')
                ->whereNotNull('category_id')->latest('id')->get();
            foreach ($drafts as $draft) {
                $draftScope = array_map('intval', Category::selfAndDescendantIds((int) $draft->category_id));
                if (array_intersect($selectedScope, $draftScope) !== []) {
                    return redirect()->route('stock-count-documents.edit', $draft)
                        ->with('warning', 'برای جلوگیری از شمارش تکراری در محدوده‌های هم‌پوشان، همان پیش‌نویس قبلی باز شد.');
                }
            }
        }
        return $this->editor(null, $category);
    }

    public function edit(StockCountDocument $document)
    {
        abort_unless($document->type === 'category', 404);
        if ($document->status !== 'draft') return redirect()->route('stock-count-documents.view', $document);
        return $this->editor($document, $document->category);
    }

    private function editor(?StockCountDocument $document, ?Category $category)
    {
        $categoryDocumentSeed = [
            'id'=>$document?->id, 'category_id'=>$category?->id,
            'category_name'=>$document?->scope_name ?? $category?->name ?? '',
            'review_token'=>$document ? $this->service->reviewToken($document) : null,
            'items'=>$document ? $document->items()->whereNotNull('actual_quantity')->orderBy('product_variant_id')->get()->map(fn ($item) => [
                'variant_id'=>(int) $item->product_variant_id, 'actual_quantity'=>(int) $item->actual_quantity,
            ])->all() : [],
            'rows_url'=>route('stock-count-documents.category-variants'),
            'save_url'=>route('stock-count-documents.category-document.store'),
            'review_url'=>$document ? route('stock-count-documents.view', $document) : null,
            'user_id'=>(int) auth()->id(),
        ];
        $rootCategories = Category::query()->whereNull('parent_id')->orderBy('name')->get(['id','name']);
        return view('stocktake.category-document', compact('categoryDocumentSeed','rootCategories'));
    }

    public function store(Request $request)
    {
        return app(StocktakeRequestService::class)->run($request, 'unified_save', function () use ($request) {
            $data = $request->validate([
                'category_id'=>['required','integer','exists:categories,id'],
                'document_id'=>['nullable','integer','exists:stock_count_documents,id'],
                'expected_review_token'=>['nullable','string','size:64'],
                'document_date'=>['nullable','date'],
                'actual_quantities'=>['nullable','array','max:50000'],
                'actual_quantities.*'=>['required','integer','min:0','max:2147483647'],
                'removed_variant_ids'=>['nullable','array','max:50000'],
                'removed_variant_ids.*'=>['required','integer','distinct','min:1'],
                'notes'=>['nullable','array','max:50000'],
                'notes.*'=>['nullable','string','max:1000'],
            ]);
            $document = $this->service->saveCategoryDraft($data, (int) $request->user()->id);
            return response()->json($this->responsePayload($document))->header('Cache-Control','private, no-store');
        });
    }

    public function review(StockCountDocument $document)
    {
        abort_unless($document->type === 'category', 404);
        $document->load(['warehouse','category','creator']);
        $countedItems = $document->items()->whereNotNull('actual_quantity')->orderBy('product_name_snapshot')->orderBy('product_variant_id')->get();
        $reviewToken = $this->service->reviewToken($document);
        return view('stocktake.category-review', compact('document','countedItems','reviewToken'));
    }

    public function finalize(Request $request, StockCountDocument $stockCountDocument)
    {
        abort_unless($stockCountDocument->type === 'category', 404);
        return app(StocktakeRequestService::class)->run($request, 'unified_finalize', function () use ($request, $stockCountDocument) {
            $data = $request->validate(['expected_review_token'=>['required','string','size:64']]);
            try {
                $document = ReservationSideEffects::transaction(fn () => $this->service->finalize($stockCountDocument, (int) $request->user()->id, false, $data));
            } catch (StockCountFinalizeValidationException $exception) {
                return response()->json(['message'=>'ثبت نهایی انجام نشد؛ هیچ ردیفی از موجودی تغییر نکرد.',
                    'errors'=>['finalize'=>[$exception->getMessage()]], 'conflicts'=>$exception->conflicts()],422);
            }
            return response()->json($this->responsePayload($document))->header('Cache-Control','private, no-store');
        });
    }

    private function responsePayload(StockCountDocument $document): array
    {
        $token = $this->service->reviewToken($document);
        $resource = [
            'id'=>(int) $document->id, 'document_number'=>$document->document_number,
            'edit_url'=>route('stock-count-documents.edit',$document), 'review_url'=>route('stock-count-documents.view',$document),
            'review_token'=>$token, 'status'=>$document->status,
            'variants_count'=>(int) $document->variants_count, 'counted_count'=>(int) $document->counted_count,
            'zeroed_count'=>(int) $document->zeroed_count, 'total_before'=>(int) $document->total_before,
            'total_actual'=>(int) $document->total_actual, 'total_increase'=>(int) $document->total_increase,
            'total_decrease'=>(int) $document->total_decrease,
            'items'=>$document->items()->whereNotNull('actual_quantity')->orderBy('product_variant_id')->get()->map(fn ($item) => [
                'variant_id'=>(int) $item->product_variant_id, 'actual_quantity'=>(int) $item->actual_quantity,
            ])->all(),
        ];
        return ['document_id'=>(int) $document->id,'view_url'=>$resource['review_url'],'review_token'=>$token,'document'=>$resource];
    }
}
