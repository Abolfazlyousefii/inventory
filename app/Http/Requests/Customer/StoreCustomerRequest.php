<?php

namespace App\Http\Requests\Customer;

use App\Rules\IranianMobile;
use App\Support\PhoneNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreCustomerRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $phones = collect($this->input('phones', []))->map(fn ($phone) => [
            'phone' => PhoneNormalizer::normalize(is_array($phone) ? ($phone['phone'] ?? '') : ''),
        ])->values()->all();

        $this->merge([
            'phones' => $phones,
            'primary_phone' => $this->input('primary_phone', 0),
            'is_active' => $this->boolean('is_active'),
        ]);
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'is_active' => ['boolean'],
            'password' => ['nullable', 'string', 'min:8', 'max:255', 'confirmed'],
            'phones' => ['required', 'array', 'min:1'],
            'phones.*.phone' => ['required', new IranianMobile, 'distinct', Rule::unique('customer_phones', 'phone')],
            'primary_phone' => ['required', 'integer', Rule::in(array_keys($this->input('phones', [])))],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'نام مشتری را وارد کنید.',
            'phones.required' => 'حداقل یک شماره موبایل وارد کنید.',
            'phones.min' => 'حداقل یک شماره موبایل وارد کنید.',
            'phones.*.phone.required' => 'شماره موبایل را وارد کنید.',
            'phones.*.phone.distinct' => 'شماره‌های موبایل نباید تکراری باشند.',
            'phones.*.phone.unique' => 'این شماره موبایل قبلاً برای مشتری دیگری ثبت شده است.',
            'primary_phone.required' => 'شماره اصلی را انتخاب کنید.',
            'primary_phone.in' => 'شماره اصلی انتخاب‌شده معتبر نیست.',
            'password.min' => 'رمز عبور مشتری باید حداقل ۸ کاراکتر باشد.',
            'password.confirmed' => 'تکرار رمز عبور با رمز عبور یکسان نیست.',
        ];
    }

    public function attributes(): array
    {
        return ['phones.*.phone' => 'شماره موبایل'];
    }
}
