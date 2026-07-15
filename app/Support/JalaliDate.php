<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Morilog\Jalali\Jalalian;

class JalaliDate
{
    public static function date(mixed $value, string $empty = '—'): string
    {
        return self::format($value, 'Y/m/d', $empty);
    }

    public static function dateTime(mixed $value, string $empty = '—'): string
    {
        return self::format($value, 'Y/m/d H:i', $empty);
    }

    public static function format(mixed $value, string $format = 'Y/m/d H:i', string $empty = '—'): string
    {
        if (blank($value)) {
            return $empty;
        }

        try {
            return Jalalian::fromDateTime($value)->format($format);
        } catch (\Throwable) {
            return $empty;
        }
    }
}
