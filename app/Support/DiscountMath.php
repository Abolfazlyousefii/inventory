<?php

namespace App\Support;

use Illuminate\Validation\ValidationException;

/** Shared discount arithmetic: whole rials, percentages in hundredths, no float money math. */
final class DiscountMath
{
    public static function input(mixed $value): string
    {
        return str_replace([',', '٬', ' '], '', strtr(trim((string) ($value ?? '0')), ['۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4', '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9', '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4', '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9', '٫' => '.']));
    }

    public static function value(string $type, mixed $value): int|string
    {
        $text = self::input($value);
        if ($text === '') {
            $text = '0';
        }
        if (! preg_match('/^\d+(?:\.\d{1,2})?$/D', $text)) {
            self::invalid();
        }
        [$whole,$fraction] = array_pad(explode('.', $text, 2), 2, '');
        $whole = ltrim($whole, '0') ?: '0';
        if (strlen($whole) > strlen((string) PHP_INT_MAX) || (strlen($whole) === strlen((string) PHP_INT_MAX) && strcmp($whole, (string) PHP_INT_MAX) > 0)) {
            self::invalid();
        }
        if ($type === 'percent') {
            if ((int) $whole > 100 || ((int) $whole === 100 && (int) $fraction > 0)) {
                self::invalid();
            }
            $fraction = rtrim($fraction, '0');

            return $fraction === '' ? (int) $whole : $whole.'.'.$fraction;
        }
        if ((int) $fraction !== 0) {
            self::invalid();
        }

        return (int) $whole;
    }

    public static function amount(int $base, string $type, mixed $value): int
    {
        $base = max($base, 0);
        $value = self::value($type, $value);
        if ($type === 'none') {
            return 0;
        }
        if ($type !== 'percent') {
            return min((int) $value, $base);
        }
        [$whole,$fraction] = array_pad(explode('.', (string) $value, 2), 2, '');
        $basis = (int) $whole * 100 + (int) str_pad($fraction, 2, '0');

        return intdiv($base, 10000) * $basis + intdiv(($base % 10000) * $basis, 10000);
    }

    public static function allocate(array $weights, int $amount): array
    {
        $weights = array_map(fn ($w) => max(0, (int) $w), $weights);
        $gross = array_sum($weights);
        if (! is_int($gross)) {
            self::invalid();
        }
        $out = array_fill_keys(array_keys($weights), 0);
        $amount = min(max($amount, 0), $gross);
        if ($gross <= 0 || $amount === 0) {
            return $out;
        }
        $positive = array_filter($weights, fn ($w) => $w > 0);
        $last = array_key_last($positive);
        $allocated = 0;
        foreach ($positive as $key => $weight) {
            $share = $key === $last ? min($weight, $amount - $allocated) : self::multiplyDivide($amount, $weight, $gross);
            $out[$key] = $share;
            $allocated += $share;
        }
        // Keep the existing last-row rounding when it fits; spill any excess back into real capacity.
        $remaining = $amount - $allocated;
        foreach (array_reverse(array_keys($positive)) as $key) {
            $extra = min($remaining, $weights[$key] - $out[$key]);
            $out[$key] += $extra;
            $remaining -= $extra;
            if ($remaining === 0) {
                break;
            }
        }

        return $out;
    }

    private static function multiplyDivide(int $a, int $b, int $denominator): int
    {
        if ($a === 0 || $b === 0) {
            return 0;
        }
        if ($a <= intdiv(PHP_INT_MAX, $b)) {
            return intdiv($a * $b, $denominator);
        }
        $quotient = 0;
        $remainder = 0;
        foreach (str_split(decbin($b)) as $bit) {
            $quotient *= 2;
            if ($remainder >= $denominator - $remainder) {
                $remainder -= $denominator - $remainder;
                $quotient++;
            } else {
                $remainder *= 2;
            }
            if ($bit === '1') {
                if ($remainder >= $denominator - $a) {
                    $remainder -= $denominator - $a;
                    $quotient++;
                } else {
                    $remainder += $a;
                }
            }
        }

        return $quotient;
    }

    private static function invalid(): never
    {
        throw ValidationException::withMessages(['discount' => 'تخفیف نامعتبر است؛ مبلغ باید ریال صحیح و درصد بین صفر تا صد با حداکثر دو رقم اعشار باشد.']);
    }
}
