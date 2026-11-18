<?php

namespace App\StockListeners;

use App\Events\SiteStockHoldChangeRequested;
use App\Models\SiteStockHold;
use App\Services\WarehouseStockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use LogicException;

/**
 * The only physical warehouse-stock writer for Inventory API Site holds.
 * Bundle allocations are a separate, already-reserved ledger: they must NOT
 * be deducted a second time when an automatic bundle is held.
 *
 * Two timestamps on the existing hold record are durable replay markers.
 * They are committed together with the warehouse quantity changes, with no
 * new operation-history table or stock-movement rows.
 */
final class ApplySiteStockHoldChange
{
    public function handle(SiteStockHoldChangeRequested $event): void
    {
        $event->assertNotHandled();

        if (DB::transactionLevel() < 1) {
            throw new LogicException('Site stock changes require the source hold transaction.');
        }

        $hold = SiteStockHold::query()->whereKey($event->holdId)->lockForUpdate()->firstOrFail();
        if ($hold->status !== 'held' || $hold->released_at !== null || $hold->invoice_id !== null) {
            throw ValidationException::withMessages(['hold' => 'The Site hold is not in a mutable state.']);
        }

        $isReserve = $event->operation === SiteStockHoldChangeRequested::RESERVE;
        $markerColumn = $isReserve ? 'stock_applied_at' : 'stock_returned_at';
        if ($hold->getAttribute($markerColumn) !== null) {
            throw ValidationException::withMessages(['hold' => 'This Site hold stock change has already been applied.']);
        }
        if (! $isReserve && $hold->confirmed_at !== null) {
            throw ValidationException::withMessages(['hold' => 'A paid Site hold cannot be released.']);
        }

        // These lines are immutable after hold creation; lock them in stable order.
        $lines = $hold->lines()->orderBy('variant_id')->lockForUpdate()->get();
        if ($lines->isEmpty()) {
            throw ValidationException::withMessages(['hold' => 'The Site hold has no physical lines.']);
        }

        $raw = DB::table('site_stock_holds')->where('id', $hold->id)->value('bundle_allocated_lines');
        $byBundle = $raw ? json_decode((string) $raw, true, 512, JSON_THROW_ON_ERROR) : [];
        if (! is_array($byBundle)) {
            throw new LogicException('Invalid hold bundle allocation snapshot.');
        }
        $protected = [];
        foreach ($byBundle as $parts) {
            if (! is_array($parts)) {
                throw new LogicException('Invalid protected bundle allocation.');
            }
            foreach ($parts as $variantId => $quantity) {
                $validUnits = is_int($quantity)
                    || (is_string($quantity) && ctype_digit($quantity));
                if ((int) $variantId < 1 || ! $validUnits || (int) $quantity < 1) {
                    throw new LogicException('Invalid protected component quantity.');
                }
                $variantId = (int) $variantId;
                $units = (int) $quantity;
                if ($units > PHP_INT_MAX - ($protected[$variantId] ?? 0)) {
                    throw new LogicException('Protected component quantity overflow.');
                }
                $protected[$variantId] = ($protected[$variantId] ?? 0) + $units;
            }
        }

        $warehouseId = WarehouseStockService::centralWarehouseId();
        $normalByVariant = [];
        foreach ($lines as $line) {
            $variantId = (int) $line->variant_id;
            $quantity = (int) $line->quantity;
            if ($variantId < 1 || (int) $line->product_id < 1 || $quantity < 1) {
                throw new LogicException('Invalid Site hold physical line.');
            }
            $normal = $quantity - (int) ($protected[$variantId] ?? 0);
            if ($normal < 0) {
                throw new LogicException('Protected bundle units exceed physical hold units.');
            }
            unset($protected[$variantId]);
            $normalByVariant[$variantId] = ['product_id' => (int) $line->product_id, 'quantity' => $normal];
        }
        if ($protected !== []) {
            throw new LogicException('Protected bundle allocation is absent from physical hold lines.');
        }

        foreach ($normalByVariant as $variantId => $entry) {
            if ($entry['quantity'] > 0) {
                WarehouseStockService::change(
                    $warehouseId,
                    $entry['product_id'],
                    ($isReserve ? -1 : 1) * $entry['quantity'],
                    (int) $variantId,
                );
            }
        }

        // Idempotency marker shares the exact transaction with ALL stock updates.
        // A different event object cannot re-apply this hold, even if its parent
        // status has not yet been changed by the outer service.
        $changed = DB::table('site_stock_holds')
            ->where('id', $hold->id)
            ->whereNull($markerColumn)
            ->update([$markerColumn => now(), 'updated_at' => now()]);
        if ($changed !== 1) {
            throw new LogicException('The Site hold stock operation was already finalized.');
        }
        $event->markHandled();
    }
}
