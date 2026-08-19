<?php

namespace App\Support;

class PhoneNormalizer
{
    public static function normalize(string $phone): string
    {
        $phone = strtr(trim($phone), [
            '۰'=>'0', '۱'=>'1', '۲'=>'2', '۳'=>'3', '۴'=>'4', '۵'=>'5', '۶'=>'6', '۷'=>'7', '۸'=>'8', '۹'=>'9',
            '٠'=>'0', '١'=>'1', '٢'=>'2', '٣'=>'3', '٤'=>'4', '٥'=>'5', '٦'=>'6', '٧'=>'7', '٨'=>'8', '٩'=>'9',
        ]);
        $phone = preg_replace('/\D+/', '', $phone) ?? '';

        if (str_starts_with($phone, '0098')) {
            $phone = substr($phone, 4);
        } elseif (str_starts_with($phone, '98')) {
            $phone = substr($phone, 2);
        }

        return strlen($phone) === 10 && str_starts_with($phone, '9') ? '0'.$phone : $phone;
    }
}
