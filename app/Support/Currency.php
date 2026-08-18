<?php

namespace App\Support;

class Currency
{
    public static function toRial(int|float|string|null $rial): int
    {
        return self::normalizeRial($rial);
    }

    public static function formatRial(int|float|string|null $rial): string
    {
        return number_format(self::normalizeRial($rial)).' ریال';
    }

    public static function formatRialNumber(int|float|string|null $rial): string
    {
        return number_format(self::normalizeRial($rial));
    }

    public static function formatRawRial(int|float|string|null $rial): string
    {
        return self::formatRial($rial);
    }

    public static function formatRawRialNumber(int|float|string|null $rial): string
    {
        return self::formatRialNumber($rial);
    }

    public static function rialInput(mixed $value): int
    {
        return self::normalizeRialString((string) ($value ?? ''));
    }

    public static function formatToman(int|float|string|null $rial): string
    {
        return self::formatTomanNumber($rial).' تومان';
    }

    public static function formatTomanNumber(int|float|string|null $rial): string
    {
        return number_format(self::rialToToman(self::normalizeSignedRial($rial)));
    }

    public static function tomanInput(mixed $value): int
    {
        $toman = self::normalizeRialString((string) ($value ?? ''));

        if ($toman > intdiv(PHP_INT_MAX, 10)) {
            return PHP_INT_MAX;
        }

        return $toman * 10;
    }

    public static function signedTomanInput(mixed $value): int
    {
        $text = trim((string) ($value ?? ''));
        $amount = self::tomanInput($text);

        return str_contains($text, '-') ? -$amount : $amount;
    }

    public static function rialToToman(int $rial): int
    {
        $absolute = abs($rial);
        $rounded = intdiv($absolute + 5, 10);

        return $rial < 0 ? -$rounded : $rounded;
    }

    private static function normalizeRial(int|float|string|null $rial): int
    {
        if (is_string($rial)) {
            return self::normalizeRialString($rial);
        }

        return max(0, (int) $rial);
    }

    private static function normalizeSignedRial(int|float|string|null $rial): int
    {
        if (! is_string($rial)) {
            return (int) $rial;
        }

        $negative = str_contains(trim($rial), '-');
        $amount = self::normalizeRialString($rial);

        return $negative ? -$amount : $amount;
    }

    private static function normalizeRialString(string $value): int
    {
        $digits = preg_replace('/[^0-9]/', '', self::englishDigits($value));
        if ($digits === '' || $digits === null) {
            return 0;
        }

        return max(0, (int) $digits);
    }

    private static function englishDigits(string $value): string
    {
        return strtr($value, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }
}
