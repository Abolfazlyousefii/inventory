<?php

namespace App\Http\Requests\Finance;

use App\Support\JalaliDate;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

final class CalculatePaymentScheduleRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Route authorization is provided by the ERP's route.permission middleware.
        return true;
    }

    protected function prepareForValidation(): void
    {
        $data = $this->all();
        foreach (['price_rial', 'monthly_percent', 'base_date'] as $key) {
            if (isset($data[$key]) && is_scalar($data[$key])) {
                $data[$key] = $this->normalize((string) $data[$key], $key === 'price_rial' ? 'money' : ($key === 'monthly_percent' ? 'decimal' : 'date'));
            }
        }
        foreach (['fixed_payments', 'weighted_payments'] as $group) {
            if (! isset($data[$group]) || ! is_array($data[$group])) continue;
            foreach ($data[$group] as $index => $item) {
                if (! is_array($item)) continue;
                foreach (['due_date', 'amount_rial', 'weight'] as $key) {
                    if (isset($item[$key]) && is_scalar($item[$key])) {
                        $type = $key === 'due_date' ? 'date' : ($key === 'amount_rial' ? 'money' : 'decimal');
                        $data[$group][$index][$key] = $this->normalize((string) $item[$key], $type);
                    }
                }
            }
        }
        $this->replace($data);
    }

    public function rules(): array
    {
        return [
            'price_rial' => ['required', 'regex:/^[0-9]{1,15}$/D', 'integer', 'min:1'],
            'monthly_percent' => ['required', 'regex:/^[0-9]{1,3}(?:\.[0-9]{1,4})?$/D', 'numeric', 'between:0,100'],
            'base_date' => ['required', 'string'],
            'fixed_payments' => ['sometimes', 'array', 'max:40'],
            'fixed_payments.*' => ['required', 'array'],
            'fixed_payments.*.kind' => ['required', 'in:cash,cheque'],
            'fixed_payments.*.due_date' => ['required', 'string'],
            'fixed_payments.*.amount_rial' => ['required', 'regex:/^[0-9]{1,15}$/D', 'integer', 'min:0'],
            'weighted_payments' => ['sometimes', 'array', 'max:40'],
            'weighted_payments.*' => ['required', 'array'],
            'weighted_payments.*.due_date' => ['required', 'string'],
            'weighted_payments.*.weight' => ['required', 'regex:/^[0-9]{1,7}(?:\.[0-9]{1,4})?$/D', 'numeric', 'gt:0', 'max:1000000'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            $data = $this->all();
            foreach (['base_date', ...$this->dateKeys('fixed_payments'), ...$this->dateKeys('weighted_payments')] as $path) {
                $value = data_get($data, $path);
                if (is_string($value) && JalaliDate::toGregorianDate($value) === null) {
                    $validator->errors()->add($path, 'تاریخ شمسی واردشده معتبر نیست.');
                }
            }
        });
    }

    private function dateKeys(string $group): array
    {
        $data = $this->input($group, []);
        return is_array($data)
            ? array_map(fn ($index) => $group.'.'.$index.'.due_date', array_keys($data))
            : [];
    }

    private function normalize(string $value, string $type): string
    {
        $value = strtr(trim($value), [
            '۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9',
            '٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9',
            '٫'=>'.','−'=>'-',
        ]);
        if ($type === 'money') return str_replace([',', '٬', ' '], '', $value);
        if ($type === 'decimal') return str_replace(['٪', '%', ' ', ','], ['', '', '', '.'], $value);
        return str_replace('-', '/', $value);
    }

    public function messages(): array
    {
        return [
            'price_rial.required' => 'مبلغ پایه قرارداد را وارد کنید.',
            'price_rial.regex' => 'مبلغ پایه باید عدد صحیح ریالی باشد.',
            'monthly_percent.required' => 'نرخ سود ماهانه را وارد کنید.',
            'monthly_percent.between' => 'درصد سود ماهانه باید بین صفر و صد باشد.',
            'monthly_percent.regex' => 'نرخ سود را با حداکثر چهار رقم اعشار وارد کنید.',
            'fixed_payments.max' => 'حداکثر ۴۰ پرداخت ثابت قابل ثبت است.',
            'weighted_payments.max' => 'حداکثر ۴۰ چک وزنی قابل ثبت است.',
            'fixed_payments.*.amount_rial.regex' => 'مبلغ پرداخت ثابت باید عدد صحیح ریالی باشد.',
            'weighted_payments.*.weight.gt' => 'وزن هر چک باید مثبت باشد.',
            'weighted_payments.*.weight.regex' => 'وزن چک باید عددی با حداکثر چهار رقم اعشار باشد.',
        ];
    }
}
