<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Pagination\Cursor;
use Illuminate\Validation\Rule;
use Morilog\Jalali\Jalalian;

class InvoiceLiveFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'order_code' => self::normalizeDigits(trim((string) $this->query('order_code', ''))),
            'date_from' => self::normalizeDate(trim((string) $this->query('date_from', ''))),
            'date_to' => self::normalizeDate(trim((string) $this->query('date_to', ''))),
            'quick_range' => trim((string) $this->query('quick_range', '')),
            'include_summary' => $this->boolean('include_summary') ? 1 : 0,
            'limit' => $this->query('limit', 40),
        ]);
    }

    public function rules(): array
    {
        return [
            'order_code' => ['nullable', 'regex:/^\d{1,5}$/'],
            'customer_id' => ['nullable', 'integer', 'exists:customers,id'],
            'date_from' => ['nullable', 'regex:/^\d{4}\/\d{2}\/\d{2}$/'],
            'date_to' => ['nullable', 'regex:/^\d{4}\/\d{2}\/\d{2}$/'],
            'quick_range' => ['nullable', Rule::in(['today', 'week', 'month'])],
            'limit' => ['integer', 'min:10', 'max:50'],
            'cursor' => ['nullable', 'string', 'max:2048'],
            'include_summary' => ['integer', Rule::in([0, 1])],
        ];
    }

    public function after(): array
    {
        return [function ($validator): void {
            $from = $this->jalaliDate('date_from');
            $to = $this->jalaliDate('date_to');

            if ($this->filled('date_from') && $from === null) {
                $validator->errors()->add('date_from', 'تاریخ شروع معتبر نیست.');
            }
            if ($this->filled('date_to') && $to === null) {
                $validator->errors()->add('date_to', 'تاریخ پایان معتبر نیست.');
            }
            if ($from && $to && $from->gt($to)) {
                $validator->errors()->add('date_to', 'تاریخ شروع نباید بعد از تاریخ پایان باشد.');
            }
            if ($this->filled('cursor') && Cursor::fromEncoded((string) $this->input('cursor')) === null) {
                $validator->errors()->add('cursor', 'نشانگر صفحه نامعتبر است.');
            }
        }];
    }

    public function messages(): array
    {
        return [
            'order_code.regex' => 'کد سفارش باید فقط عدد و حداکثر ۵ رقم باشد.',
            'customer_id.exists' => 'مشتری انتخاب‌شده معتبر نیست.',
            'quick_range.in' => 'بازه سریع انتخاب‌شده معتبر نیست.',
            'limit.max' => 'حداکثر ۵۰ فاکتور در هر درخواست مجاز است.',
        ];
    }

    public function jalaliDate(string $key): ?\Carbon\Carbon
    {
        $value = (string) $this->input($key, '');
        if ($value === '' || preg_match('/^(\d{4})\/(\d{2})\/(\d{2})$/', $value, $parts) !== 1) {
            return null;
        }

        try {
            $jalali = new Jalalian((int) $parts[1], (int) $parts[2], (int) $parts[3]);
            if ($jalali->format('Y/m/d') !== $value) {
                return null;
            }

            return $jalali->toCarbon();
        } catch (\Throwable) {
            return null;
        }
    }

    public static function normalizeDigits(string $value): string
    {
        return strtr($value, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }

    private static function normalizeDate(string $value): string
    {
        $value = self::normalizeDigits($value);
        $value = str_replace(['-', '.', ' '], '/', $value);

        if (preg_match('/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/', $value, $parts) === 1) {
            return sprintf('%04d/%02d/%02d', $parts[1], $parts[2], $parts[3]);
        }

        return $value;
    }
}
