<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Invoice;
use App\Models\PreinvoiceDraftReservation;
use App\Models\PreinvoiceOrder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\SiteStockHold;
use App\Models\User;
use App\Support\ActivityLogger;
use App\Support\ReservationSideEffects;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;

/** Central-stock count boundary. No public HTTP parameter can bypass this guard. */
class InventoryCountFreezeService
{
    private int $internalDepth = 0;

    private int $stocktakeDepth = 0;

    public const MESSAGE = 'انبارگردانی این گروه فعال است؛ تغییر موجودی، رزرو و تأیید مالی تا پایان شمارش بسته است. پیش‌نویس را می‌توانید نگه دارید.';

    public function state(bool $exclusive = false): ?object
    {
        // Older installations and small isolated legacy tests have no control table yet.
        if (! Schema::hasTable('inventory_count_freezes')) {
            return null;
        }
        $q = DB::table('inventory_count_freezes')->where('id', 1);
        if (DB::transactionLevel() > 0) {
            $exclusive ? $q->lockForUpdate() : $q->sharedLock();
        }

        return $q->first();
    }

    public function fence(): void
    {
        if ($this->internalDepth > 0) {
            return;
        }
        $this->state();
    }

    public function assertInvoice(Invoice $invoice): void
    {
        foreach ($invoice->items()->pluck('product_id')->unique() as $id) {
            $this->assertProduct((int) $id);
        }
    }

    public function blocked(int $productId): bool
    {
        $s = $this->state();
        if (! $s || ! $s->enabled) {
            return false;
        }
        if ($s->all_products) {
            return true;
        }
        $ids = json_decode($s->product_ids ?? '[]', true);
        if (in_array($productId, $ids, true)) {
            return true;
        }
        $categories = [];
        foreach (json_decode($s->category_ids ?? '[]', true) as $id) {
            $categories = array_merge($categories, Category::selfAndDescendantIds((int) $id));
        }
        if (Product::whereKey($productId)->whereIn('category_id', $categories)->exists()) {
            return true;
        }

        // A newly defined bundle must not provide a way to sell a counted component.
        return DB::table('product_bundle_components as recipe')->join('product_variants as bundle', 'bundle.id', '=', 'recipe.bundle_variant_id')->join('product_variants as part', 'part.id', '=', 'recipe.component_variant_id')->join('products as p', 'p.id', '=', 'part.product_id')->where('bundle.product_id', $productId)->where(function ($q) use ($ids, $categories) {
            $q->whereIn('p.id', $ids)->orWhereIn('p.category_id', $categories);
        })->exists();
    }

    public function assertProduct(int $productId, ?int $warehouseId = null): void
    {
        if ($this->internalDepth || $this->stocktakeDepth) {
            return;
        }
        if (! $this->state()?->enabled) {
            return;
        }
        if ($warehouseId !== null && $warehouseId !== WarehouseStockService::centralWarehouseId()) {
            return;
        }
        if ($this->blocked($productId)) {
            throw ValidationException::withMessages(['stocktake' => self::MESSAGE]);
        }
    }

    public function forStocktake(callable $fn): mixed
    {
        $this->stocktakeDepth++;
        try {
            return $fn();
        } finally {
            $this->stocktakeDepth--;
        }
    }

    private function internally(callable $fn): mixed
    {
        $this->internalDepth++;
        try {
            return $fn();
        } finally {
            $this->internalDepth--;
        }
    }

    public function preview(array $categoryIds, bool $all): array
    {
        $categories = [];
        foreach ($categoryIds as $id) {
            $categories = array_merge($categories, Category::selfAndDescendantIds((int) $id));
        }
        $ids = Product::query()->when(! $all, fn ($q) => $q->whereIn('category_id', $categories))->pluck('id')->map(fn ($id) => (int) $id)->all();
        // Include bundles containing these products; never mutate the other categories' ordinary stock.
        $bundleIds = DB::table('product_bundle_components as r')->join('product_variants as part', 'part.id', '=', 'r.component_variant_id')->join('product_variants as bundle', 'bundle.id', '=', 'r.bundle_variant_id')->whereIn('part.product_id', $ids)->pluck('bundle.product_id')->map(fn ($id) => (int) $id)->all();
        $ids = array_values(array_unique(array_merge($ids, $bundleIds)));
        sort($ids);
        $rows = PreinvoiceDraftReservation::whereIn('product_id', $ids)->whereNull('released_at')->whereNull('converted_at')->whereNull('release_reason')->where('quantity', '>', 0);

        $orderIds = (clone $rows)->whereNotNull('preinvoice_order_id')->distinct()->pluck('preinvoice_order_id');
        $releaseRows = PreinvoiceDraftReservation::where(fn ($q) => $q->whereIn('product_id', $ids)->orWhereIn('preinvoice_order_id', $orderIds))->whereNull('released_at')->whereNull('converted_at')->whereNull('release_reason')->where('quantity', '>', 0);
        $classificationRows = (clone $releaseRows)->with(['order.invoice', 'activeDrafts'])->get();
        $historical = $classificationRows->filter(fn ($row) => app(ReservationClassificationService::class)->classify($row)['state'] === ReservationClassificationService::STATE_HISTORICAL_AMBIGUOUS)->count();

        return ['product_ids' => $ids, 'products' => count($ids), 'reservation_rows' => $classificationRows->count(), 'reservation_quantity' => (int) $classificationRows->sum('quantity'), 'orders' => $orderIds->count(), 'historical_rows' => $historical];
    }

    public function activate(array $categories, bool $all, User $actor, ?int $expectedVersion = null): array
    {
        abort_unless($actor->isAdministrator(), 403);
        if (! $all && ! $categories) {
            throw ValidationException::withMessages(['category_ids' => 'حداقل یک دسته را انتخاب کنید.']);
        }

        return $this->internally(fn () => ReservationSideEffects::transaction(function () use ($categories, $all, $actor, $expectedVersion) {
            DB::table('inventory_count_freezes')->insertOrIgnore(['id' => 1, 'enabled' => false, 'all_products' => false, 'created_at' => now(), 'updated_at' => now()]);
            $before = $this->state(true);
            if ($expectedVersion !== null && (int) $before->version !== $expectedVersion) {
                throw ValidationException::withMessages(['version' => 'وضعیت انبارگردانی تغییر کرده است؛ صفحه را تازه‌سازی کنید.']);
            }
            if ($before->enabled) {
                throw ValidationException::withMessages(['enabled' => 'انبارگردانی فعال است؛ برای تغییر محدوده ابتدا آن را پایان دهید.']);
            }
            $preview = $this->preview($categories, $all);
            $ids = $preview['product_ids'];
            // Paid/external orders must never be silently cancelled or refunded by an ERP stocktake switch.
            if (Schema::hasTable('site_stock_holds') && SiteStockHold::whereIn('status', ['held', 'payment_confirmed'])->whereHas('lines', fn ($q) => $q->whereIn('product_id', $ids))->exists()) {
                throw ValidationException::withMessages(['stocktake' => 'سفارش سایت با رزرو فعال در این گروه وجود دارد؛ ابتدا وضعیت سفارش سایت را تعیین تکلیف کنید. هیچ رزروی آزاد نشد.']);
            }
            $rows = PreinvoiceDraftReservation::whereIn('product_id', $ids)->whereNull('released_at')->whereNull('converted_at')->whereNull('release_reason')->where('quantity', '>', 0)->lockForUpdate()->get();
            $orderIds = $rows->pluck('preinvoice_order_id')->filter()->unique();
            $orders = PreinvoiceOrder::whereIn('id', $orderIds)->orderBy('id')->lockForUpdate()->get();
            foreach ($orders as $order) {
                if ($order->invoice()->exists() || $order->status === PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE) {
                    throw ValidationException::withMessages(['stocktake' => 'رزرو متصل به فاکتور صادرشده نیازمند بررسی است؛ هیچ موجودی برگردانده نشد.']);
                }
            }
            // Release the entire unconverted order, so finance never sees partially reserved documents.
            $rows = PreinvoiceDraftReservation::where(function ($q) use ($ids, $orderIds) {
                $q->whereIn('product_id', $ids)->orWhereIn('preinvoice_order_id', $orderIds);
            })->whereNull('released_at')->whereNull('converted_at')->whereNull('release_reason')->where('quantity', '>', 0)->orderBy('id')->lockForUpdate()->get();
            $released = 0;
            $archived = 0;
            $this->internally(function () use ($rows, $orders, $actor, &$released, &$archived) {
                foreach ($rows as $row) {
                    $classification = app(ReservationClassificationService::class)->classify($row, now());
                    if ($classification['state'] === ReservationClassificationService::STATE_HISTORICAL_AMBIGUOUS) {
                        $archive = app(HistoricalReservationArchiveService::class)->archive([$row->id], now(), $actor->id);
                        if ($archive['archived'] !== 1) {
                            throw ValidationException::withMessages(['stocktake' => 'وضعیت رزرو تاریخی تغییر کرد؛ دوباره بررسی کنید.']);
                        }
                        ReservationSideEffects::touchProduct((int) $row->product_id);
                        $archived++;

                        continue;
                    }
                    if (! in_array($classification['state'], [ReservationClassificationService::STATE_ACTIVE_VALID, ReservationClassificationService::STATE_TEMPORARY_ACTIVE, ReservationClassificationService::STATE_TEMPORARY_STALE_RELEASABLE, ReservationClassificationService::STATE_OFFICIAL_ACTIVE], true)) {
                        throw ValidationException::withMessages(['stocktake' => 'رزرو شماره '.$row->id.' سابقهٔ مبهم یا نامعتبر دارد؛ ابتدا بررسی شود. هیچ موجودی آزاد نشد.']);
                    }
                    app(PreinvoiceBundleStockService::class)->release((int) $row->product_id, (int) $row->variant_id, (int) $row->quantity);
                    $row->forceFill(['released_at' => now(), 'released_by' => $actor->id, 'release_reason' => 'stocktake_freeze', 'release_note' => 'آزادسازی برای شروع انبارگردانی؛ سند و اقلام حفظ شدند.'])->save();
                    ReservationSideEffects::touchProduct((int) $row->product_id);
                    $released += (int) $row->quantity;
                }
                foreach ($orders as $order) {
                    $old = $order->status;
                    $order->forceFill(['status' => PreinvoiceOrder::STATUS_DRAFT, 'stock_frozen_until' => null, 'stock_released_at' => now(), 'is_auto_draft' => false, 'items_updated_at' => now(), 'items_updated_by' => $actor->id])->save();
                    $order->reviews()->create(['user_id' => $actor->id, 'action' => 'stocktake_freeze', 'reason' => 'بازگشت به پیش‌نویس برای انبارگردانی؛ ثبت مجدد پس از پایان شمارش لازم است.', 'before_items' => ['status' => $old], 'after_items' => ['status' => 'draft']]);
                }
            });
            // Return remaining, unsold bundle allocations to ordinary stock before physical counting.
            // Already consumed/sold units are absent from remaining and are never restored.
            $bundleVariants = ProductVariant::whereIn('product_id', $ids)->whereHas('product', fn ($q) => $q->where('is_bundle', true)->where('bundle_stock_mode', 'automatic'))->pluck('id');
            foreach ($bundleVariants as $vid) {
                $parts = DB::table('product_bundle_components')->where('bundle_variant_id', $vid)->pluck('component_variant_id')->all();
                if ($parts) {
                    app(BundleComponentAllocationService::class)->setAllocations((int) $vid, array_fill_keys($parts, 0));
                }
            }
            DB::table('inventory_count_freezes')->where('id', 1)->update(['enabled' => true, 'version' => (int) $before->version + 1, 'all_products' => $all, 'category_ids' => json_encode(array_values(array_map('intval', $categories))), 'product_ids' => json_encode($ids), 'changed_by' => $actor->id, 'updated_at' => now()]);
            $result = $preview + ['released_quantity' => $released, 'released_rows' => $rows->count() - $archived, 'archived_rows' => $archived, 'reservation_ids' => $rows->pluck('id')->all(), 'order_ids' => $orderIds->values()->all()];
            ActivityLogger::logForActor($actor->id, 'inventory_count_freeze.enabled', $actor, 'شروع انبارگردانی و توقف عملیات موجودی مرکزی', $result + ['category_ids' => $categories, 'all_products' => $all]);

            return $result;
        }));
    }

    public function deactivate(User $actor, ?int $expectedVersion = null): void
    {
        abort_unless($actor->isAdministrator(), 403);
        DB::transaction(function () use ($actor, $expectedVersion) {
            $before = $this->state(true);
            if ($before && $expectedVersion !== null && (int) $before->version !== $expectedVersion) {
                throw ValidationException::withMessages(['version' => 'وضعیت انبارگردانی تغییر کرده است؛ صفحه را تازه‌سازی کنید.']);
            }
            if (! $before || ! $before->enabled) {
                return;
            }
            DB::table('inventory_count_freezes')->where('id', 1)->update(['enabled' => false, 'version' => (int) $before->version + 1, 'changed_by' => $actor->id, 'updated_at' => now()]);
            ActivityLogger::logForActor($actor->id, 'inventory_count_freeze.disabled', $actor, 'پایان انبارگردانی؛ رزروها خودکار برنگشتند.', ['before' => (array) $before]);
        });
    }
}
