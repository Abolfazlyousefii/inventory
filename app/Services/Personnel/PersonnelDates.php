<?php

namespace App\Services\Personnel;

use Carbon\Carbon;
use Illuminate\Validation\ValidationException;
use Morilog\Jalali\Jalalian;

class PersonnelDates
{
    public static function digits(string $value): string
    {
        return str_replace(['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹', '٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'], str_split('01234567890123456789'), trim($value));
    }

    public static function parse(string $value, string $field): Carbon
    {
        $value = self::digits($value);
        try {
            if (! preg_match('~^1[34][0-9]{2}/[0-9]{2}/[0-9]{2}$~', $value)) {
                throw new \InvalidArgumentException;
            }
            $date = Jalalian::fromFormat('Y/m/d', $value);
            if ($date->format('Y/m/d') !== $value) {
                throw new \InvalidArgumentException;
            }

            return $date->toCarbon()->startOfDay();
        } catch (\Throwable) {
            throw ValidationException::withMessages([$field => 'تاریخ شمسی معتبر با قالب ۱۴۰۵/۰۹/۰۱ وارد کنید.']);
        }
    }

    public static function display($date, string $format = 'Y/m/d'): string
    {
        return $date ? Jalalian::fromDateTime($date)->format($format) : '—';
    }
}
