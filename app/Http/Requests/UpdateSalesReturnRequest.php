<?php

namespace App\Http\Requests;

class UpdateSalesReturnRequest extends StoreSalesReturnRequest
{
    public function rules(): array
    {
        $pending = $this->route('document')?->isPendingWarehouse() ?? false;

        return parent::rules() + [
            'document_version' => [$pending ? 'required' : 'nullable', 'string', 'size:64'],
            'adjustment_reason' => [$pending ? 'required' : 'nullable', 'string', 'min:3', 'max:1000'],
        ];
    }

    public function messages(): array
    {
        return parent::messages() + [
            'adjustment_reason.required' => 'دلیل اصلاح سند را وارد کنید.',
            'adjustment_reason.min' => 'دلیل اصلاح باید حداقل ۳ نویسه باشد.',
            'adjustment_reason.max' => 'دلیل اصلاح نباید بیش از ۱۰۰۰ نویسه باشد.',
            'document_version.required' => 'نسخه سند مشخص نیست؛ صفحه را تازه کنید.',
            'document_version.size' => 'نسخه سند نامعتبر است؛ صفحه را تازه کنید.',
        ];
    }
}
