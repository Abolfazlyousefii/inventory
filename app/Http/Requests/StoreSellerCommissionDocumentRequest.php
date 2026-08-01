<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\NormalizesSellerCommissionDates;
use Illuminate\Foundation\Http\FormRequest;

class StoreSellerCommissionDocumentRequest extends FormRequest
{
    use NormalizesSellerCommissionDates;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return $this->sellerCommissionDateRules() + [
            'invoice_ids' => ['required', 'array', 'min:1'],
            'invoice_ids.*' => ['required', 'integer', 'distinct', 'exists:invoices,id'],
            'notes' => ['nullable', 'string', 'max:2000'],
        ];
    }

    public function attributes(): array
    {
        return [
            'user_id' => 'کاربر فروشنده',
            'date_from' => 'از تاریخ',
            'date_to' => 'تا تاریخ',
            'invoice_ids' => 'فاکتورها',
            'notes' => 'توضیحات',
        ];
    }
}
