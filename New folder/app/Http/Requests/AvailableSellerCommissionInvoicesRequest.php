<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesSellerCommissionDates;
use Illuminate\Foundation\Http\FormRequest;

class AvailableSellerCommissionInvoicesRequest extends FormRequest
{
    use NormalizesSellerCommissionDates;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return $this->sellerCommissionDateRules() + [
            'search' => ['nullable', 'string', 'max:100'],
            'document_id' => ['nullable', 'integer', 'exists:seller_sales_documents,id'],
            'page' => ['nullable', 'integer', 'min:1'],
        ];
    }
}
