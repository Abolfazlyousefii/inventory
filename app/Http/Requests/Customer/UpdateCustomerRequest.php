<?php

namespace App\Http\Requests\Customer;

use App\Models\Customer;
use App\Rules\IranianMobile;
use App\Support\PhoneNormalizer;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UpdateCustomerRequest extends FormRequest
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
        /** @var Customer $customer */
        $customer = $this->route('customer');

        return [
            'name' => ['required', 'string', 'max:255'],
            'company_name' => ['nullable', 'string', 'max:255'],
            'city' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:5000'],
            'notes' => ['nullable', 'string', 'max:10000'],
            'is_active' => ['boolean'],
            'password' => ['nullable', 'string', 'min:8', 'max:255', 'confirmed'],
            'phones' => ['required', 'array', 'min:1'],
            'phones.*.phone' => [
                'required',
                new IranianMobile,
                'distinct',
                Rule::unique('customer_phones', 'phone')->where(fn ($query) => $query->where('customer_id', '<>', $customer->id)),
            ],
            'primary_phone' => ['required', 'integer', Rule::in(array_keys($this->input('phones', [])))],
        ];
    }

    public function messages(): array
    {
        return (new StoreCustomerRequest)->messages();
    }

    public function attributes(): array
    {
        return ['phones.*.phone' => 'شماره موبایل'];
    }
}
