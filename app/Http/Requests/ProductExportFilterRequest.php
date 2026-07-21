<?php

namespace App\Http\Requests;

use App\Models\Category;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProductExportFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $input = $this->all();

        foreach (['root_category_id', 'subcategory_id', 'category_id', 'model_list_id', 'stock_status', 'page'] as $key) {
            if (array_key_exists($key, $input) && $input[$key] === '') {
                $input[$key] = null;
            }
        }

        $modelListIds = $input['model_list_ids'] ?? [];
        if (! is_array($modelListIds)) {
            $modelListIds = [$modelListIds];
        }
        if (! empty($input['model_list_id'])) {
            $modelListIds[] = $input['model_list_id'];
        }
        $input['model_list_ids'] = collect($modelListIds)
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->map(fn ($value) => (int) $value)
            ->filter(fn (int $value) => $value > 0)
            ->unique()
            ->values()
            ->all();

        if (! empty($input['category_id']) && empty($input['root_category_id']) && empty($input['subcategory_id'])) {
            $category = Category::query()->find((int) $input['category_id'], ['id', 'parent_id']);
            if ($category && $category->parent_id) {
                $input['subcategory_id'] = $category->id;
                $input['root_category_id'] = $category->parent_id;
            } elseif ($category) {
                $input['root_category_id'] = $category->id;
            }
        }

        $input['stock_status'] = $input['stock_status'] ?: 'all';

        $this->replace($input);
    }

    public function rules(): array
    {
        return [
            'root_category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'subcategory_id' => ['nullable', 'integer', 'exists:categories,id'],
            'model_list_ids' => ['nullable', 'array', 'max:100'],
            'model_list_ids.*' => ['integer', 'distinct', 'exists:model_lists,id'],
            'stock_status' => ['nullable', Rule::in(['all', 'in_stock', 'out_of_stock'])],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }

    public function filters(): array
    {
        $validated = $this->validated();

        return [
            'root_category_id' => $validated['root_category_id'] ?? null,
            'subcategory_id' => $validated['subcategory_id'] ?? null,
            'model_list_ids' => $validated['model_list_ids'] ?? [],
            'stock_status' => $validated['stock_status'] ?? 'all',
            'page' => $validated['page'] ?? 1,
        ];
    }
}
