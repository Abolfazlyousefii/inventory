<?php

namespace App\Support;

use App\Models\SalesReturnDocument;
use App\Models\WarehouseInboundReceipt;
use App\Services\SalesReturnCorrectionService;
use Illuminate\Validation\ValidationException;

/**
 * Once a sales return has been finalized through central receiving,
 * its original document is immutable. The decision deliberately includes
 * approved inbound receipts and historical central stock effects, not only the current item destination.
 */
final class SalesReturnFinalization
{
    public const MESSAGE = 'این سند پس از تأیید دریافت انبار مرکزی نهایی شده و دیگر قابل ویرایش یا ابطال نیست.';

    public static function isLocked(SalesReturnDocument $document): bool
    {
        if (! $document->isApplied()) {
            return false;
        }

        // A warehouse manager may receive a queued central return into the
        // returns warehouse after inspection. The approved receipt is still
        // final, even if no line's current destination remains central.
        $received = WarehouseInboundReceipt::query()
            ->where('source_type', WarehouseInboundReceipt::SOURCE_SALES_RETURN)
            ->where('source_id', $document->id)
            ->whereIn('status', [
                WarehouseInboundReceipt::STATUS_RECEIVED,
                WarehouseInboundReceipt::STATUS_DISCREPANCY,
            ])
            ->exists();

        return $received
            || app(SalesReturnCorrectionService::class)->requiresWarehouse($document);
    }

    public static function assertMutable(SalesReturnDocument $document): void
    {
        if (self::isLocked($document)) {
            throw ValidationException::withMessages(['document' => self::MESSAGE]);
        }
    }
}
