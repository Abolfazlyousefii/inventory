<?php

namespace App\Http\Requests\Concerns;

use App\Support\JalaliDate;

trait NormalizesSellerCommissionDates
{
    protected function prepareForValidation(): void
    {
        $normalized = [];

        foreach (['date_from', 'date_to'] as $field) {
            $value = trim((string) $this->input($field));
            if ($value !== '' && (str_contains($value, '/') || ! preg_match('/^\d{4}-\d{2}-\d{2}$/', $value))) {
                $normalized[$field] = JalaliDate::toGregorianDate($value) ?: $value;
            }
        }

        $this->merge($normalized);
    }

    protected function sellerCommissionDateRules(): array
    {
        return [
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'date_from' => ['required', 'date_format:Y-m-d'],
            'date_to' => ['required', 'date_format:Y-m-d', 'after_or_equal:date_from'],
        ];
    }
}
