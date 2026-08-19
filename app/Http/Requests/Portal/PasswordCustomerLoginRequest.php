<?php

namespace App\Http\Requests\Portal;

use App\Rules\IranianMobile;
use App\Support\PhoneNormalizer;
use Illuminate\Foundation\Http\FormRequest;

class PasswordCustomerLoginRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'phone' => PhoneNormalizer::normalize((string) $this->input('phone')),
        ]);
    }

    public function rules(): array
    {
        return [
            'phone' => ['required', new IranianMobile],
            'password' => ['required', 'string', 'max:255'],
        ];
    }

    public function messages(): array
    {
        return [
            'phone.required' => 'شماره موبایل را وارد کنید.',
            'password.required' => 'رمز عبور را وارد کنید.',
        ];
    }
}
