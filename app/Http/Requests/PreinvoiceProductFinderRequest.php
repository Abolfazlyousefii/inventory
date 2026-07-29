<?php

namespace App\Http\Requests;

use App\Support\PermissionCatalog;
use App\Support\ProductFinderSearchNormalizer;
use Illuminate\Foundation\Http\FormRequest;

class PreinvoiceProductFinderRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user() !== null
            && PermissionCatalog::userHasPermission($this->user(), 'preinvoices.create');
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'q' => ProductFinderSearchNormalizer::normalize($this->input('q')),
            'in_stock_only' => filter_var($this->input('in_stock_only', true), FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? true,
        ]);
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'category_id' => ['nullable', 'integer', 'exists:categories,id'],
            'subcategory_id' => ['nullable', 'integer', 'exists:categories,id'],
            'in_stock_only' => ['required', 'boolean'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:10', 'max:50'],
        ];
    }
}
