<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

class Percentage
{
    public static function normalize(mixed $value): string
    {
        $normalized = strtr(trim((string) $value), [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
            '٫' => '.', ',' => '.', '٬' => '', '%' => '', '٪' => '',
        ]);

        if (! is_numeric($normalized) || (float) $normalized < 0 || (float) $normalized > 100) {
            throw ValidationException::withMessages(['percentage' => 'درصد باید عددی بین صفر تا صد باشد.']);
        }

        return number_format((float) $normalized, 4, '.', '');
    }
}
