<?php

namespace App\Support;

use Carbon\CarbonInterface;
use Carbon\Carbon;
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

    public static function toGregorianDate(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        $normalized = strtr(trim((string) $value), [
            '۰'=>'0', '۱'=>'1', '۲'=>'2', '۳'=>'3', '۴'=>'4',
            '۵'=>'5', '۶'=>'6', '۷'=>'7', '۸'=>'8', '۹'=>'9',
            '٠'=>'0', '١'=>'1', '٢'=>'2', '٣'=>'3', '٤'=>'4',
            '٥'=>'5', '٦'=>'6', '٧'=>'7', '٨'=>'8', '٩'=>'9',
            '-'=>'/',
        ]);

        if (! preg_match('/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/', $normalized, $matches)) {
            return null;
        }

        try {
            $canonical = sprintf('%04d/%02d/%02d', $matches[1], $matches[2], $matches[3]);
            $date = Jalalian::fromFormat('Y/m/d', $canonical)->toCarbon();
            if (Jalalian::fromDateTime($date)->format('Y/m/d') !== $canonical) {
                return null;
            }

            return $date->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }

    public static function gregorianDate(mixed $value, string $empty = ''): string
    {
        if (blank($value)) {
            return $empty;
        }

        try {
            return Carbon::parse($value)->format('Y-m-d');
        } catch (\Throwable) {
            return $empty;
        }
    }
}
