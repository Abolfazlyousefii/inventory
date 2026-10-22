<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\ProductVariant;
use App\Models\Site\Order as SiteOrder;
use App\Models\SiteStockHold;
use App\Models\WarehouseStock;
use App\Support\HoldLinePlan;
use App\Support\AutomaticBundlePlan;
use App\Models\Product;
use App\Services\BundleComponentAllocationService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Throwable;

/** An all-or-nothing physical stock hold shared by ordinary products and bundle SKUs. */
class SiteOrderStockHoldService
{
    /** @param array<int,array{variant_id:int,quantity:int}> $lines */
    public function reserve(int $siteOrderId, array $lines, int $ttlMinutes): SiteStockHold
    {
        if ($siteOrderId <= 0 || $ttlMinutes < 1 || $ttlMinutes > 1440) {
            throw ValidationException::withMessages(['expires_in_minutes' => 'Invalid stock hold duration or order ID.']);
        }
        try {
            $plan = HoldLinePlan::normalize($lines);
        } catch (InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['lines' => $exception->getMessage()]);
        }
        // Fingerprint is COMMERCIAL order lines, while held stock is COMPONENT lines.
        $hash = HoldLinePlan::fingerprint($plan);
        $physicalPlan = $this->physicalPlan($plan);
        $bundleRequirements = $this->allocatedBundleRequirements($plan);
        // All items are kept in hold lines for invoice verification, but allocated
        // bundle components must NOT be deducted from ordinary warehouse stock twice.
        $allocatedTotals = [];
        foreach ($bundleRequirements as $parts) foreach ($parts as $id => $units) {
            $allocatedTotals[$id] = ($allocatedTotals[$id] ?? 0) + $units;
        }
        $normalPlan = $physicalPlan;
        foreach ($allocatedTotals as $id => $units) {
            $normalPlan[$id] -= $units;
            if ($normalPlan[$id] < 0) throw new \LogicException('Allocated kit demand exceeds the physical plan.');
            if ($normalPlan[$id] === 0) unset($normalPlan[$id]);
        }
        try {
            return DB::transaction(function () use ($siteOrderId, $physicalPlan, $normalPlan, $bundleRequirements, $plan, $hash, $ttlMinutes) {
                $existing = SiteStockHold::query()->where('site_order_id', $siteOrderId)->lockForUpdate()->first();
                if ($existing) return $this->sameReservation($existing, $hash);

                // Reserve already-protected component units; ordinary stock was reduced
                // when the administrator allocated these units to the bundle.
                app(BundleComponentAllocationService::class)->reserve($bundleRequirements);

                $variants = ProductVariant::query()->whereIn('id', array_keys($physicalPlan))
                    ->orderBy('id')->get()->keyBy('id');
                if ($variants->count() !== count($physicalPlan)) {
                    throw ValidationException::withMessages(['lines' => 'Unknown inventory variant.']);
                }

                $warehouseId = WarehouseStockService::centralWarehouseId();
                $rows = WarehouseStock::query()->where('warehouse_id', $warehouseId)
                    ->whereIn('product_variant_id', array_keys($normalPlan))
                    ->orderBy('product_variant_id')->lockForUpdate()->get()->keyBy('product_variant_id');
                foreach ($normalPlan as $id => $qty) {
                    if ((int) ($rows->get($id)?->quantity ?? 0) < $qty) {
                        throw ValidationException::withMessages(['stock' => "Insufficient stock for variant {$id}."]);
                    }
                }

                $hold = SiteStockHold::query()->create([
                    'site_order_id' => $siteOrderId, 'fingerprint' => $hash,
                    'status' => 'held', 'expires_at' => now()->addMinutes($ttlMinutes),
                ]);
                if ($bundleRequirements !== []) {
                    DB::table('site_stock_holds')->where('id', $hold->id)->update([
                        'bundle_allocated_lines' => json_encode($bundleRequirements, JSON_THROW_ON_ERROR),
                    ]);
                }
                foreach ($physicalPlan as $id => $qty) {
                    $variant = $variants->get($id);
                    $hold->lines()->create([
                        'variant_id' => $id, 'product_id' => $variant->product_id, 'quantity' => $qty,
                    ]);
                    // Deduct from sellable warehouse stock NOW. Subsequent imports only consume the hold.
                    if (($normalPlan[$id] ?? 0) > 0) {
                        WarehouseStockService::change($warehouseId, (int) $variant->product_id, -(int) $normalPlan[$id], $id);
                    }
                }
                return $hold->fresh(['lines']);
            }, 3);
        } catch (QueryException $exception) {
            // Unique site_order_id index is the final concurrent-retry defense.
            $existing = SiteStockHold::query()->where('site_order_id', $siteOrderId)->first();
            if ($existing) return $this->sameReservation($existing, $hash);
            throw $exception;
        }
    }

    /** @return array<int,array<int,int>> bundle variant => protected component consumption */
    private function allocatedBundleRequirements(array $commercial): array
    {
        $variants = ProductVariant::query()->with('product')->whereIn('id', array_keys($commercial))->get()->keyBy('id');
        $requirements = [];
        foreach ($commercial as $variantId => $units) {
            $variant = $variants->get($variantId);
            if (!$variant?->product?->is_bundle || $variant->product->bundle_stock_mode !== 'automatic') continue;
            $recipe = DB::table('product_bundle_components')->where('bundle_variant_id', $variantId)
                ->pluck('quantity', 'component_variant_id');
            if ($recipe->isEmpty()) throw ValidationException::withMessages(['lines' => 'بسته بدون قطعه است.']);
            foreach ($recipe as $partId => $perBundle) {
                if ($units > intdiv(PHP_INT_MAX, (int) $perBundle)) {
                    throw ValidationException::withMessages(['lines' => 'تعداد بسته بیش از حد مجاز است.']);
                }
                $requirements[(int) $variantId][(int) $partId] = (int) $perBundle * $units;
            }
        }
        return $requirements;
    }

    /** Expand automatic bundles before row locks; no virtual SKU stock row is decremented. */
    private function physicalPlan(array $commercial): array
    {
        $variants = ProductVariant::query()->with('product')->whereIn('id', array_keys($commercial))->get()->keyBy('id');
        if ($variants->count() !== count($commercial)) {
            throw ValidationException::withMessages(['lines' => 'Unknown Inventory variant.']);
        }
        $recipes = [];
        foreach ($commercial as $variantId => $quantity) {
            $variant = $variants->get($variantId);
            if (!$variant->is_active || !$variant->sales_enabled || !$variant->product?->is_sellable) {
                throw ValidationException::withMessages(['lines' => 'Inactive or unsellable variant.']);
            }
            if (!$variant->product?->is_bundle || $variant->product->bundle_stock_mode !== 'automatic') continue;
            $recipes[$variantId] = DB::table('product_bundle_components')
                ->where('bundle_variant_id', $variantId)
                ->pluck('quantity', 'component_variant_id')
                ->mapWithKeys(fn ($qty, $id) => [(int) $id => (int) $qty])->all();
            if (!$recipes[$variantId]) throw ValidationException::withMessages(['lines' => 'Automatic bundle has no components.']);
        }
        try {
            $physical = AutomaticBundlePlan::expand($commercial, $recipes);
        } catch (InvalidArgumentException $e) {
            throw ValidationException::withMessages(['lines' => $e->getMessage()]);
        }
        $physicalVariants = ProductVariant::query()->with('product')->whereIn('id', array_keys($physical))->get();
        if ($physicalVariants->count() !== count($physical)) throw ValidationException::withMessages(['lines' => 'A component variant is missing.']);
        foreach ($physicalVariants as $component) {
            if (!$component->is_active || !$component->sales_enabled || !$component->product?->is_sellable
                || ($component->product?->is_bundle && $component->product->bundle_stock_mode === 'automatic')) {
                throw ValidationException::withMessages(['lines' => 'Component is unavailable for sale.']);
            }
        }
        return $physical;
    }

    public function confirm(int $siteOrderId, int $holdId): SiteStockHold
    {
        return DB::transaction(function () use ($siteOrderId, $holdId) {
            $hold = $this->locked($siteOrderId, $holdId);
            if (in_array($hold->status, ['payment_confirmed', 'consumed'], true)) return $hold;
            if ($hold->status !== 'held') {
                throw ValidationException::withMessages(['hold' => 'Stock hold was released; use payment recovery/refund.']);
            }
            // Confirm is called only after Site verifies the payment, never from a browser.
            $hold->update(['status' => 'payment_confirmed', 'confirmed_at' => now()]);
            return $hold->fresh();
        }, 3);
    }

    public function release(int $siteOrderId, int $holdId, bool $expired = false): SiteStockHold
    {
        return DB::transaction(function () use ($siteOrderId, $holdId, $expired) {
            $hold = $this->locked($siteOrderId, $holdId);
            if (in_array($hold->status, ['released', 'expired'], true)) return $hold;
            if ($hold->status !== 'held') {
                throw ValidationException::withMessages(['hold' => 'Paid/consumed stock cannot be released.']);
            }
            $allocationJson = DB::table('site_stock_holds')->where('id', $hold->id)->value('bundle_allocated_lines');
            $allocationByBundle = $allocationJson ? json_decode((string) $allocationJson, true, 512, JSON_THROW_ON_ERROR) : [];
            $allocatedTotals = [];
            foreach ($allocationByBundle as $parts) foreach ($parts as $variantId => $qty) {
                $allocatedTotals[$variantId] = ($allocatedTotals[$variantId] ?? 0) + (int) $qty;
            }
            if ($allocationByBundle !== []) {
                app(BundleComponentAllocationService::class)->restoreByBundle($allocationByBundle);
            }
            // Backward-compatible release of old pre-allocation shared sales-cap holds.
            $legacy = json_decode((string) (DB::table('site_stock_holds')->where('id', $hold->id)->value('bundle_shared_allocations') ?? '[]'), true, 512, JSON_THROW_ON_ERROR);
            foreach ($legacy as $productId => $count) {
                $oldProduct = Product::query()->whereKey((int) $productId)->lockForUpdate()->firstOrFail();
                $oldProduct->update(['bundle_shared_remaining' => (int) $oldProduct->bundle_shared_remaining + (int) $count]);
            }
            $warehouseId = WarehouseStockService::centralWarehouseId();
            foreach ($hold->lines()->orderBy('variant_id')->get() as $line) {
                $normalQty = (int) $line->quantity - (int) ($allocatedTotals[$line->variant_id] ?? 0);
                if ($normalQty < 0) throw new \LogicException('Stock hold allocation exceeds held component units.');
                if ($normalQty > 0) {
                    WarehouseStockService::change($warehouseId, (int) $line->product_id,
                        $normalQty, (int) $line->variant_id);
                }
            }
            $hold->update(['status' => $expired ? 'expired' : 'released', 'released_at' => now()]);
            return $hold->fresh();
        }, 3);
    }

    /** Must run inside the same transaction as creating the paid Inventory invoice. */
    public function consumeForPaidInvoice(int $siteOrderId, int $holdId, array $resolvedItems, Invoice $invoice): void
    {
        $hold = $this->locked($siteOrderId, $holdId);
        if ($hold->status === 'consumed' && (int) $hold->invoice_id === (int) $invoice->id) return;
        if (!in_array($hold->status, ['held', 'payment_confirmed'], true)) {
            throw ValidationException::withMessages(['hold' => 'Paid order has no available stock hold. Manual refund/recovery needed.']);
        }
        $actual = HoldLinePlan::normalize(array_map(static fn ($row): array => [
            'variant_id' => (int) $row['variant']->id,
            'quantity' => (int) $row['quantity'],
        ], $resolvedItems));
        if (HoldLinePlan::fingerprint($actual) !== $hold->fingerprint) {
            throw ValidationException::withMessages(['hold' => 'Invoice physical lines do not match the original stock hold.']);
        }
        // No stock change: reserved items were subtracted atomically before payment.
        $hold->update([
            'status' => 'consumed', 'confirmed_at' => $hold->confirmed_at ?? now(),
            'consumed_at' => now(), 'invoice_id' => $invoice->id,
        ]);
    }

    /** Never expire a paid Site order or release stock when Site status cannot be checked. */
    public function expireUnpaid(int $limit = 100): int
    {
        // Request-driven mode: a Site status check and the hold release must use
        // a single cross-schema InnoDB transaction with stable lock order.
        // The legacy cross-connection check below remains for API fallback.
        if (config('services.request_driven_site_stock', false)) {
            return $this->expireUnpaidWithSiteRowLock($limit);
        }
        $count = 0;
        SiteStockHold::query()->where('status', 'held')->where('expires_at', '<=', now())
            ->orderBy('id')->limit($limit)->pluck('id')->each(function ($holdId) use (&$count): void {
                $hold = SiteStockHold::query()->find($holdId);
                if (!$hold) return;
                try {
                    $siteStatus = SiteOrder::query()->whereKey($hold->site_order_id)->value('status');
                    if (!in_array($siteStatus, ['unpaid', 'canceled'], true)) return;
                    $this->release((int) $hold->site_order_id, (int) $hold->id, true);
                    $count++;
                } catch (Throwable $exception) {
                    Log::warning('Site stock hold expiry deferred: Site verification unavailable.', [
                        'hold_id' => $holdId, 'exception' => $exception::class,
                    ]);
                }
            });
        return $count;
    }

    /**
     * No cron required: executed before warehouse changes and on relevant pages.
     * Lock the Site order before the Inventory hold to avoid expiring a payment
     * that has already been marked paid by the callback.
     */
    private function expireUnpaidWithSiteRowLock(int $limit): int
    {
        $inv = DB::connection();
        $site = DB::connection('site');
        if ($inv->getDriverName() !== 'mysql' || $site->getDriverName() !== 'mysql') {
            throw new \RuntimeException('Hold recovery requires MySQL for both schemas.');
        }
        foreach (['host','port','unix_socket'] as $key) {
            if ((string) config('database.connections.mysql.'.$key)
                !== (string) config('database.connections.site.'.$key)) {
                throw new \RuntimeException('Site and Inventory must share one MySQL server.');
            }
        }
        $schema = (string) $site->getDatabaseName();
        if (!preg_match('/^[A-Za-z0-9_]+$/D', $schema)
            || $schema === $inv->getDatabaseName()) {
            throw new \RuntimeException('Invalid Site schema for hold expiration.');
        }
        $ids = SiteStockHold::query()->where('status', 'held')
            ->where('expires_at', '<=', now())->orderBy('expires_at')
            ->limit(max(1, min(50, $limit)))->pluck('id');
        $count = 0;
        foreach ($ids as $id) {
            try {
                $done = DB::transaction(function () use ($schema, $id): bool {
                    $before = SiteStockHold::query()->find($id);
                    if (!$before || $before->status !== 'held' || $before->expires_at->isFuture()) return false;
                    $order = DB::table($schema.'.orders')->where('id', $before->site_order_id)
                        ->lockForUpdate()->first(['id', 'status']);
                    if (!$order || !in_array($order->status, ['unpaid', 'canceled'], true)) return false;
                    $hold = SiteStockHold::query()->whereKey($id)->lockForUpdate()->first();
                    if (!$hold || $hold->status !== 'held' || $hold->expires_at->isFuture()) return false;
                    // release() uses a nested transaction on the SAME connection.
                    // The order lock is retained until allocation restoration commits.
                    $this->release((int) $order->id, (int) $hold->id, true);
                    return true;
                }, 3);
                if ($done) $count++;
            } catch (\Throwable $e) {
                Log::warning('Request-driven Site hold expiration deferred.', [
                    'hold_id' => (int) $id, 'exception' => $e::class,
                ]);
            }
        }
        return $count;
    }

    private function sameReservation(SiteStockHold $hold, string $hash): SiteStockHold
    {
        if (!hash_equals($hold->fingerprint, $hash) || !in_array($hold->status, ['held', 'payment_confirmed', 'consumed'], true)) {
            throw ValidationException::withMessages(['hold' => 'Existing order has a different or released reservation.']);
        }
        return $hold->fresh(['lines']);
    }

    private function locked(int $siteOrderId, int $holdId): SiteStockHold
    {
        return SiteStockHold::query()->whereKey($holdId)
            ->where('site_order_id', $siteOrderId)->lockForUpdate()->firstOrFail();
    }
}
