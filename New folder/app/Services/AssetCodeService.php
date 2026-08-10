<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class AssetCodeService
{
    public function parseCodes(string $input): array
    {
        $normalizedInput = $this->normalizeDigits($input);
        $normalized = preg_replace('/[\s,،]+/u', ',', trim($normalizedInput));
        if (!$normalized) {
            return [];
        }

        return collect(explode(',', $normalized))
            ->map(fn ($code) => trim($code))
            ->filter(fn ($code) => $code !== '')
            ->values()
            ->all();
    }

    public function validateFourDigitCodes(array $codes, string $attribute = 'asset_codes'): array
    {
        foreach ($codes as $code) {
            if (!preg_match('/^\d{4}$/', (string) $code)) {
                throw ValidationException::withMessages([
                    $attribute => 'هر کد اموال باید دقیقاً ۴ رقم باشد.',
                ]);
            }
        }

        $seen = [];
        foreach ($codes as $code) {
            if (isset($seen[$code])) {
                throw ValidationException::withMessages([
                    $attribute => "کد اموال {$code} در این ردیف تکراری است.",
                ]);
            }

            $seen[$code] = true;
        }

        return $codes;
    }

    private function normalizeDigits(string $value): string
    {
        return strtr($value, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }
}
