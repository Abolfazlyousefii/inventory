<?php

namespace App\Http\Requests;

use App\Models\Category;
use App\Models\ModelList;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ProductExportFilterRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        $input = $this->all();
        foreach (['root_category_id','subcategory_id','category_id','model_list_id','model_brand','stock_status','page','q'] as $key) {
            if (array_key_exists($key, $input) && $input[$key] === '') $input[$key] = null;
        }
        $modelListIds = $input['model_list_ids'] ?? [];
        if (! is_array($modelListIds)) $modelListIds = [$modelListIds];
        if (! empty($input['model_list_id'])) $modelListIds[] = $input['model_list_id'];
        $input['model_list_ids'] = collect($modelListIds)->filter(fn($v)=>$v!==null && $v!=='')->map(fn($v)=>(int)$v)->filter(fn($v)=>$v>0)->unique()->values()->all();
        $productIds = $input['product_ids'] ?? [];
        if (! is_array($productIds)) $productIds = [$productIds];
        $input['product_ids'] = collect($productIds)
            ->filter(fn ($value) => $value !== null && $value !== '')
            ->map(fn ($value) => is_string($value) && preg_match('/^\d+$/', $value) ? (int) $value : $value)
            ->unique(fn ($value) => is_scalar($value) ? (string) $value : serialize($value))
            ->values()
            ->all();
        if (! empty($input['category_id']) && empty($input['root_category_id']) && empty($input['subcategory_id'])) {
            $category = Category::query()->find((int) $input['category_id'], ['id','parent_id']);
            if ($category && $category->parent_id) { $input['subcategory_id']=$category->id; $input['root_category_id']=$category->parent_id; }
            elseif ($category) $input['root_category_id']=$category->id;
        }
        $input['stock_status'] = ($input['stock_status'] ?? null) ?: 'all';
        $truthy = ['1', 'true', 'on', 1, true];
        $input['include_without_price'] = in_array($input['include_without_price'] ?? false, $truthy, true);
        $this->replace($input);
    }

    public function rules(): array
    {
        return [
            'root_category_id' => ['nullable','integer','exists:categories,id'],
            'subcategory_id' => ['nullable','integer','exists:categories,id'],
            'model_brand' => ['nullable','string','max:100'],
            'model_list_ids' => ['nullable','array','max:200'],
            'model_list_ids.*' => ['integer','distinct','exists:model_lists,id'],
            'product_ids' => ['nullable','array','max:200'],
            'product_ids.*' => ['integer','distinct','exists:products,id'],
            'stock_status' => ['nullable', Rule::in(['all','in_stock','out_of_stock'])],
            'include_without_price' => ['nullable','boolean'],
            'q' => ['nullable','string','max:100'],
            'page' => ['nullable','integer','min:1'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $ids = collect($this->input('model_list_ids', []))->filter();
            if ($ids->isEmpty()) return;
            $brand = trim((string) $this->input('model_brand', ''));
            if ($brand === '') { $validator->errors()->add('model_brand', 'برای انتخاب مدل، نوع مدل لیست را انتخاب کنید.'); return; }
            $count = ModelList::query()->whereIn('id', $ids->all())->where('brand', $brand)->count();
            if ($count !== $ids->count()) $validator->errors()->add('model_list_ids', 'مدل‌های انتخاب‌شده با نوع مدل لیست مطابقت ندارند.');
        });
    }

    public function filters(): array
    {
        $v = $this->validated();
        return [
            'root_category_id' => $v['root_category_id'] ?? null,
            'subcategory_id' => $v['subcategory_id'] ?? null,
            'model_brand' => $v['model_brand'] ?? null,
            'model_list_ids' => $v['model_list_ids'] ?? [],
            'product_ids' => $v['product_ids'] ?? [],
            'stock_status' => $v['stock_status'] ?? 'all',
            'include_without_price' => (bool) ($v['include_without_price'] ?? false),
            'page' => $v['page'] ?? 1,
        ];
    }

    public function messages(): array
    {
        return [
            'product_ids.array' => 'فهرست محصولات انتخابی نامعتبر است.',
            'product_ids.max' => 'حداکثر ۲۰۰ محصول را می‌توان انتخاب کرد.',
            'product_ids.*.integer' => 'شناسه محصول انتخابی نامعتبر است.',
            'product_ids.*.distinct' => 'محصول تکراری در فهرست انتخابی وجود دارد.',
            'product_ids.*.exists' => 'یکی از محصولات انتخاب‌شده دیگر در سامانه وجود ندارد.',
        ];
    }
}
