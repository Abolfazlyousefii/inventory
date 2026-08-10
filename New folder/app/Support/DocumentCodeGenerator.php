<?php

namespace App\Support;

use App\Models\Invoice;
use App\Models\PreinvoiceOrder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

class DocumentCodeGenerator
{
    public const DEFAULT_WIDTH = 5;
    private const INVOICE_SEQUENCE_TYPE = 'invoice';
    private const INITIAL_INVOICE_SEQUENCE_FLOOR = 117;

    /**
     * Generates the next sequential numeric document code with leading zeros.
     *
     * Invoice and preinvoice official codes share one locked sequence so a code
     * reserved for a preinvoice is reused by its invoice instead of a new code
     * being generated during conversion. If the sequence table is unavailable
     * (for example before the new migration has run), this falls back to the
     * legacy per-model max-code strategy.
     */
    public static function generateSequentialCode(string $modelClass, string $column = 'uuid', int $width = self::DEFAULT_WIDTH): string
    {
        if (!is_subclass_of($modelClass, Model::class)) {
            throw new RuntimeException("{$modelClass} must be an Eloquent model.");
        }

        if ($width < 1) {
            throw new RuntimeException('Document code width must be at least 1.');
        }

        if ($width === self::DEFAULT_WIDTH && $column === 'uuid' && self::usesOfficialInvoiceSequence($modelClass)) {
            return self::nextInvoiceCode($width);
        }

        return self::legacyNextCode($modelClass, $column, $width);
    }

    public static function nextInvoiceCode(int $width = self::DEFAULT_WIDTH): string
    {
        return DB::transaction(function () use ($width) {
            if (! Schema::hasTable('document_sequences')) {
                return self::legacyNextSharedInvoiceCode($width);
            }

            $now = now();
            DB::table('document_sequences')->updateOrInsert(
                ['type' => self::INVOICE_SEQUENCE_TYPE],
                ['last_number' => 0, 'created_at' => $now, 'updated_at' => $now]
            );

            $sequence = DB::table('document_sequences')
                ->where('type', self::INVOICE_SEQUENCE_TYPE)
                ->lockForUpdate()
                ->first();

            $lastNumber = max((int) ($sequence->last_number ?? 0), self::currentMaxOfficialInvoiceNumber($width));
            $next = $lastNumber + 1;
            $limit = (10 ** $width) - 1;

            if ($next > $limit) {
                throw new RuntimeException("Unable to generate a unique {$width}-digit invoice code.");
            }

            DB::table('document_sequences')
                ->where('type', self::INVOICE_SEQUENCE_TYPE)
                ->update(['last_number' => $next, 'updated_at' => $now]);

            return str_pad((string) $next, $width, '0', STR_PAD_LEFT);
        });
    }

    public static function generateUnique5DigitCode(string $modelClass, string $column = 'uuid'): string
    {
        return self::generateSequentialCode($modelClass, $column, self::DEFAULT_WIDTH);
    }

    public static function generateUnique4DigitCode(string $modelClass, string $column = 'uuid'): string
    {
        return self::generateUnique5DigitCode($modelClass, $column);
    }

    private static function usesOfficialInvoiceSequence(string $modelClass): bool
    {
        return in_array($modelClass, [Invoice::class, PreinvoiceOrder::class], true);
    }

    private static function currentMaxOfficialInvoiceNumber(int $width): int
    {
        $max = self::INITIAL_INVOICE_SEQUENCE_FLOOR;

        foreach ([Invoice::class, PreinvoiceOrder::class] as $modelClass) {
            /** @var Model $model */
            $model = new $modelClass();
            $table = $model->getTable();

            if (! Schema::hasTable($table) || ! Schema::hasColumn($table, 'uuid')) {
                continue;
            }

            $value = DB::table($table)
                ->pluck('uuid')
                ->filter(fn ($code) => is_string($code) && preg_match('/^\d{' . $width . '}$/', $code) === 1)
                ->map(fn ($code) => (int) $code)
                ->max() ?? 0;

            $max = max($max, (int) $value);
        }

        return $max;
    }

    private static function legacyNextSharedInvoiceCode(int $width): string
    {
        $maxValue = self::currentMaxOfficialInvoiceNumber($width);
        $limit = (10 ** $width) - 1;

        for ($next = $maxValue + 1; $next <= $limit; $next++) {
            $code = str_pad((string) $next, $width, '0', STR_PAD_LEFT);

            if (! Invoice::query()->where('uuid', $code)->exists() && ! PreinvoiceOrder::query()->where('uuid', $code)->exists()) {
                return $code;
            }
        }

        throw new RuntimeException("Unable to generate a unique {$width}-digit invoice code.");
    }

    private static function legacyNextCode(string $modelClass, string $column, int $width): string
    {
        $maxValue = $modelClass::query()
            ->pluck($column)
            ->filter(fn ($code) => is_string($code) && preg_match('/^\d{' . $width . '}$/', $code) === 1)
            ->map(fn ($code) => (int) $code)
            ->max() ?? 0;

        $limit = (10 ** $width) - 1;

        for ($next = $maxValue + 1; $next <= $limit; $next++) {
            $code = str_pad((string) $next, $width, '0', STR_PAD_LEFT);

            if (!$modelClass::query()->where($column, $code)->exists()) {
                return $code;
            }
        }

        throw new RuntimeException("Unable to generate a unique {$width}-digit code.");
    }
}
