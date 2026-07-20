<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VoidAppliedSalesReturnRequest extends FormRequest
{
    public function authorize(): bool { return true; }
    public function rules(): array { return ['reason' => ['required', 'string', 'min:3', 'max:1000']]; }
    public function messages(): array { return ['reason.required' => 'دلیل حذف الزامی است.']; }
}
