<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\ModelList;
use App\Services\ProductExportBuilderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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

    public function products(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'brand' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', 'in:relevant,name,low,high'],
            'in_stock' => ['nullable', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        return response()->json($this->builder->page($filters));
    }

    public function print(Request $request): View
    {
        return view('product-exports.builder-print', $this->selectionData($request));
    }

    public function preview(Request $request): View|JsonResponse
    {
        $data = $this->selectionData($request);
        if ($request->boolean('copy_text')) {
            $text = $data['products']->map(function ($product) use ($data) {
                $text = $product['name']."\n".collect($product['selected_models'])->map(fn ($model) => '• '.$model)->implode("\n");
                if ($data['options']['price']) {
                    $text .= "\nقیمت حدودی: ".$product['approximate_price_label'];
                }
                return $text;
            })->implode("\n\n");
            return response()->json(['text' => strtr($text, array_combine(str_split('0123456789'), preg_split('//u', '۰۱۲۳۴۵۶۷۸۹', -1, PREG_SPLIT_NO_EMPTY)))]);
        }
        return view('product-exports.partials.builder-sheet', $data);
    }

    private function selectionData(Request $request): array
    {
        $data = $request->validate([
            'selection' => ['required', 'array', 'min:1', 'max:200'],
            'selection.*' => ['required', 'array', 'min:1', 'max:500'],
            'selection.*.*' => ['required', 'integer', 'min:0'],
            'show_price' => ['nullable', 'boolean'],
            'show_stock' => ['nullable', 'boolean'],
            'show_code' => ['nullable', 'boolean'],
            'in_stock' => ['nullable', 'boolean'],
            'copy_text' => ['nullable', 'boolean'],
        ]);

        return [
            'products' => $this->builder->selected($data['selection'], (bool) ($data['in_stock'] ?? true)),
            'options' => [
                'price' => (bool) ($data['show_price'] ?? false),
                'stock' => (bool) ($data['show_stock'] ?? false),
                'code' => (bool) ($data['show_code'] ?? false),
            ],
        ];
    }
}
