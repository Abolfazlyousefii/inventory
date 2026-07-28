<?php

namespace App\Http\Requests;

use App\Support\JalaliDate;
use Illuminate\Foundation\Http\FormRequest;

class PurchaseIndexRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'date_from' => $this->normalizedDate('date_from'),
            'date_to' => $this->normalizedDate('date_to'),
        ]);
    }

    public function rules(): array
    {
        return [
            'supplier_id' => ['nullable', 'integer', 'exists:suppliers,id'],
            'date_from' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:date_to'],
            'date_to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'date_from_fa' => ['nullable', 'string'],
            'date_to_fa' => ['nullable', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'supplier_id.integer' => 'تأمین‌کننده انتخاب‌شده معتبر نیست.',
            'supplier_id.exists' => 'تأمین‌کننده انتخاب‌شده معتبر نیست.',
            'date_from.date_format' => 'تاریخ واردشده معتبر نیست.',
            'date_to.date_format' => 'تاریخ واردشده معتبر نیست.',
            'date_from.before_or_equal' => 'تاریخ شروع نمی‌تواند بعد از تاریخ پایان باشد.',
            'date_to.after_or_equal' => 'تاریخ شروع نمی‌تواند بعد از تاریخ پایان باشد.',
        ];
    }

    private function normalizedDate(string $name): ?string
    {
        $jalali = trim((string) $this->input($name.'_fa', ''));
        if ($jalali !== '') {
            return JalaliDate::toGregorianDate($jalali) ?? $jalali;
        }

        $gregorian = trim((string) $this->input($name, ''));

        return $gregorian === '' ? null : $gregorian;
    }
}
