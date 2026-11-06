<?php

namespace App\Support;

use App\Models\SalesReturnDocument;
use App\Services\SalesReturnCorrectionService;
use Illuminate\Validation\ValidationException;

/**
 * Once a sales return has been finalized with a central-warehouse receipt,
 * its original document is immutable. The decision deliberately includes
 * historical central stock effects, not only the current item destination.
 */
final class SalesReturnFinalization
{
    public const MESSAGE = 'این سند پس از تأیید دریافت انبار مرکزی نهایی شده و دیگر قابل ویرایش یا ابطال نیست.';

    public static function isLocked(SalesReturnDocument $document): bool
    {
        return $document->isApplied()
            && app(SalesReturnCorrectionService::class)->requiresWarehouse($document);
    }

    public static function assertMutable(SalesReturnDocument $document): void
    {
        if (self::isLocked($document)) {
            throw ValidationException::withMessages(['document' => self::MESSAGE]);
        }
    }
}
