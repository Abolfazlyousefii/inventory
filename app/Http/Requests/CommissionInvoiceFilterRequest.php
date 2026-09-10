<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CommissionInvoiceFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'date_from' => [$this->routeIs('finance.seller-sales.report-invoices') ? 'required' : 'nullable', 'string'],
            'date_to' => [$this->routeIs('finance.seller-sales.report-invoices') ? 'required' : 'nullable', 'string'],
            'search' => ['nullable', 'string', 'max:100'],
            'invoice_number' => [$this->routeIs('finance.seller-sales.manual-invoice') ? 'required' : 'nullable', 'string', 'regex:/^[0-9۰-۹]{5}$/'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('invoice_number')) {
            $this->merge(['invoice_number' => strtr((string) $this->input('invoice_number'), ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9'])]);
        }
    }
}
