<?php

namespace App\Http\Requests;

class UpdateAppliedSalesReturnRequest extends StoreSalesReturnRequest
{
    protected function prepareForValidation(): void
    {
        parent::prepareForValidation();
        $document = $this->route('document');
        if ($document) {
            $this->merge([
                'source_type' => $document->source_type,
                'customer_id' => $document->customer_id,
                'invoice_id' => $document->invoice_id,
                'external_invoice_number' => $document->external_invoice_number,
                'external_invoice_date' => optional($document->external_invoice_date)->format('Y-m-d'),
                'action' => 'apply',
            ]);
        }
    }

    public function rules(): array
    {
        $rules = parent::rules();
        $rules['adjustment_reason'] = ['required', 'string', 'min:3', 'max:1000'];
        return $rules;
    }

    public function messages(): array
    {
        return parent::messages() + [
            'adjustment_reason.required' => 'وارد کردن دلیل اصلاح سند الزامی است.',
            'adjustment_reason.min' => 'دلیل اصلاح سند باید حداقل ۳ نویسه باشد.',
            'adjustment_reason.max' => 'دلیل اصلاح سند نباید بیشتر از ۱۰۰۰ نویسه باشد.',
        ];
    }
}
