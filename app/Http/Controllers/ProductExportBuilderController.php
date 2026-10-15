<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\ModelList;
use App\Services\ProductExportBuilderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use JsonException;
use Illuminate\View\View;

class ProductExportBuilderController extends Controller
{
    public function __construct(private readonly ProductExportBuilderService $builder) {}

    public function index(): View
    {
        return view('product-exports.builder', [
            'categories' => Category::query()->orderBy('name')->get(['id', 'name']),
            'brands' => ModelList::query()->whereNotNull('brand')->where('brand', '<>', '')
                ->distinct()->orderBy('brand')->pluck('brand'),
        ]);
    }

    private function productFilters(Request $request): array
    {
        return $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'brand' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', 'in:relevant,name,low,high'],
            'in_stock' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'after_id' => ['nullable', 'integer', 'min:0'],
        ]);
    }

    public function products(Request $request): JsonResponse
    {
        return response()->json($this->builder->page($this->productFilters($request)));
    }

    public function selectionBatch(Request $request): JsonResponse
    {
        return response()->json($this->builder->selectionBatch($this->productFilters($request)));
    }

    public function print(Request $request): View
    {
        return view('product-exports.builder-print', $this->selectionData($request));
    }

    public function preview(Request $request): View
    {
        return view('product-exports.partials.builder-sheet', $this->selectionData($request));
    }

    private function selectionData(Request $request): array
    {
        $options = $request->validate([
            'show_price' => ['nullable', 'boolean'],
            'show_stock' => ['nullable', 'boolean'],
            'show_code' => ['nullable', 'boolean'],
        ]);

        // One JSON form field prevents max_input_vars truncation for large exports.
        // Existing clients posting selection[...] remain supported.
        if ($request->exists('selection_payload')) {
            $payload = $request->input('selection_payload');
            if (! is_string($payload)) {
                throw ValidationException::withMessages(['selection' => 'اطلاعات انتخاب کالاها معتبر نیست.']);
            }
            try {
                $selection = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException $exception) {
                throw ValidationException::withMessages(['selection' => 'اطلاعات انتخاب کالاها معتبر نیست.']);
            }
        } else {
            $selection = $request->input('selection');
        }

        $selection = Validator::make(['selection' => $selection], [
            'selection' => ['required', 'array', 'min:1'],
            'selection.*' => ['required', 'array', 'min:1'],
            'selection.*.*' => ['required', 'integer', 'min:0'],
        ])->validate()['selection'];

        return [
            'products' => $this->builder->selected($selection),
            'options' => [
                'price' => (bool) ($options['show_price'] ?? false),
                'stock' => (bool) ($options['show_stock'] ?? false),
                'code' => (bool) ($options['show_code'] ?? false),
            ],
        ];
    }
}
