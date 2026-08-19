<?php

namespace App\Rules;

use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

class IranianMobile implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || preg_match('/^09\d{9}$/', $value) !== 1) {
            $fail('شماره موبایل معتبر نیست.');
        }
    }
}
