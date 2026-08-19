<?php

namespace App\Http\Requests\Portal;

use App\Rules\IranianMobile;
use App\Support\PhoneNormalizer;
use Illuminate\Foundation\Http\FormRequest;

class RequestCustomerOtpRequest extends FormRequest
{
    public function authorize(): bool { return true; }

    protected function prepareForValidation(): void
    {
        $this->merge(['phone' => PhoneNormalizer::normalize((string) $this->input('phone'))]);
    }

    public function rules(): array
    {
        return ['phone' => ['required', new IranianMobile]];
    }
}
