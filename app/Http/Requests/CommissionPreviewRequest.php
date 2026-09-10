<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CommissionPreviewRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array
    {
        return [
            'date_from' => ['required', 'string'],
            'date_to' => ['required', 'string'],
            'invoice_ids' => ['required', 'array', 'min:1', 'max:500'],
            'invoice_ids.*' => ['required', 'integer', 'distinct', 'exists:invoices,id'],
            'manual_invoice_ids' => ['nullable', 'array'],
            'manual_invoice_ids.*' => ['integer', 'distinct', 'exists:invoices,id'],
        ];
    }
}
