<?php

namespace App\Support;

use Illuminate\Database\Eloquent\Builder;

final class VariantSearch
{
    /** Apply each word across the searchable fields, normalizing stored text too. */
    public static function apply(Builder $query, string $term, array $columns): void
    {
        $term = str_replace(['-', '_', '/', "\u{00A0}"], ' ', ProductFinderSearchNormalizer::normalize($term));
        $term = (string) preg_replace('/([a-z])\s+(?=[0-9])/u', '$1', $term);
        $grammar = $query->getQuery()->getGrammar();
        $sqlite = $query->getConnection()->getDriverName() === 'sqlite';
        if ($sqlite) {
            // SQLite has a shallow SQL parser stack; normalize each field in one call.
            $query->getConnection()->getPdo()->sqliteCreateFunction('variant_search_text', static function ($value): string {
                return str_replace([' ', '-', '_', '/', "\u{00A0}"], '', ProductFinderSearchNormalizer::normalize($value));
            }, 1);
        }
        foreach (preg_split('/\s+/u', $term, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            $expressions = [];
            foreach ($columns as $column) {
                if ($sqlite) {
                    $expressions[] = 'variant_search_text('.$grammar->wrap($column).')';
                    continue;
                }
                $expression = 'LOWER(COALESCE('.$grammar->wrap($column).", ''))";
                $from = ['ي','ى','ك','ۀ','۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
                $to = ['ی','ی','ک','ه','0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'];
                foreach (array_combine($from, $to) as $old => $new) {
                    if (str_contains($token, $new)) $expression = "REPLACE($expression, '$old', '$new')";
                }
                foreach ([' ', "\u{200C}", "\u{00A0}", '-', '_', '/', "\t", "\n", "\r"] as $separator) $expression = "REPLACE($expression, '$separator', '')";
                $expressions[] = $expression;
            }
            $pattern = '%'.strtr($token, ['!'=>'!!', '%'=>'!%', '_'=>'!_']).'%';
            $query->where(function (Builder $inner) use ($expressions, $pattern) {
                foreach ($expressions as $expression) $inner->orWhereRaw("$expression LIKE ? ESCAPE '!'", [$pattern]);
            });
        }
    }
}
