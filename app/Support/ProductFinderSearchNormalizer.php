<?php

namespace App\Support;

final class ProductFinderSearchNormalizer
{
    public static function normalize(?string $value): string
    {
        $value = strtr((string) $value, [
            "\u{064A}" => "\u{06CC}", "\u{0649}" => "\u{06CC}", "\u{0643}" => "\u{06A9}",
            "\u{06C0}" => "\u{0647}", "\u{200C}" => ' ',
            "\u{06F0}" => '0', "\u{06F1}" => '1', "\u{06F2}" => '2', "\u{06F3}" => '3', "\u{06F4}" => '4',
            "\u{06F5}" => '5', "\u{06F6}" => '6', "\u{06F7}" => '7', "\u{06F8}" => '8', "\u{06F9}" => '9',
            "\u{0660}" => '0', "\u{0661}" => '1', "\u{0662}" => '2', "\u{0663}" => '3', "\u{0664}" => '4',
            "\u{0665}" => '5', "\u{0666}" => '6', "\u{0667}" => '7', "\u{0668}" => '8', "\u{0669}" => '9',
        ]);
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $value)));
    }

    public static function compact(?string $value): string
    {
        return (string) preg_replace('/\s+/u', '', self::normalize($value));
    }

    public static function databaseVariants(?string $value): array
    {
        $normalized = self::normalize($value);
        return collect([$normalized, strtr($normalized, ["\u{06CC}" => "\u{064A}", "\u{06A9}" => "\u{0643}"])])
            ->filter()->unique()->values()->all();
    }
}
