<?php

namespace App\Services\Sync;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\WarehouseStock;
use App\Services\AriyajanebiSyncService;
use App\Services\InventoryWebhookService;
use App\Services\WarehouseStockService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/** Replays side effects otherwise emitted by Inventory model observers after commit. */
final class SiteStockChangeOutboxService
{
    public function drain(int $limit = 50): int
    {
        $claim = (string) Str::uuid();
        $rows = DB::transaction(function () use ($claim, $limit) {
            $ids = DB::table('site_stock_sync_outbox')
                ->where('available_at', '<=', now())
                ->where(fn ($q) => $q->whereNull('locked_until')
                    ->orWhere('locked_until', '<=', now()))
                ->orderBy('id')->limit(max(1, min(100, $limit)))
                ->lockForUpdate()->pluck('id');
            if ($ids->isEmpty()) return collect();
            DB::table('site_stock_sync_outbox')->whereIn('id', $ids->all())->update([
                'claimed_by' => $claim, 'locked_until' => now()->addMinutes(10),
            ]);
            return DB::table('site_stock_sync_outbox')->whereIn('id', $ids->all())->get();
        }, 3);
        if ($rows->isEmpty()) return 0;
        $handled = 0;
        // Group consecutive changes to one product, but acknowledge rows only
        // after ALL downstream notifications complete. Failed deliveries retry.
        foreach ($rows->groupBy('product_id') as $productId => $events) {
            try {
                // Update Site's existing tables first. External integrations can
                // fail independently and remain in the durable retry outbox.
                app(SyncProductsToSite::class)->syncProduct((int) $productId);
                // Native Inventory Eloquent observers have already dispatched
                // their existing webhooks. Do not notify those endpoints twice.
                // Direct Site SQL writes bypass observers, so replay side effects
                // ONLY for events originating in the Site reservation transport.
                if (!$events->contains(fn ($event) => $event->origin === 'site')) {
                    DB::table('site_stock_sync_outbox')->where('claimed_by', $claim)
                        ->whereIn('id', $events->pluck('id')->all())->delete();
                    $handled += $events->count();
                    continue;
                }
                $product = Product::query()->with('variants')->findOrFail($productId);
                $variantIds = $events->pluck('variant_id')->map(fn ($id) => (int) $id)
                    ->filter(fn ($id) => $id > 0)->unique();
                foreach ($variantIds as $variantId) {
                    $variant = $product->variants->firstWhere('id', $variantId);
                    if ($variant instanceof ProductVariant) {
                        AriyajanebiSyncService::syncVariant($variant);
                        $stock = WarehouseStock::query()
                            ->where('warehouse_id', WarehouseStockService::centralWarehouseId())
                            ->where('product_variant_id', $variantId)->first();
                        if ($stock) {
                            InventoryWebhookService::send('warehouse_stock.updated', [
                                'warehouse_id' => $stock->warehouse_id,
                                'product_id' => $productId,
                                'quantity' => $stock->quantity,
                                'changed' => ['quantity' => $stock->quantity],
                            ]);
                        }
                    }
                }
                AriyajanebiSyncService::syncProduct($product);
                InventoryWebhookService::send('product.updated', [
                    'product_id' => $product->id, 'sku' => $product->sku,
                    'name' => $product->name, 'price' => $product->price,
                    'stock' => $product->stock, 'reserved' => $product->reserved,
                    'changed' => ['stock' => $product->stock],
                ]);
                DB::table('site_stock_sync_outbox')->where('claimed_by', $claim)
                    ->whereIn('id', $events->pluck('id')->all())->delete();
                $handled += $events->count();
            } catch (Throwable $e) {
                Log::warning('Deferred direct Site stock sync failed.', [
                    'product_id' => $productId, 'exception' => $e::class,
                    'message' => $e->getMessage(),
                ]);
                DB::table('site_stock_sync_outbox')->where('claimed_by', $claim)
                    ->whereIn('id', $events->pluck('id')->all())
                    ->update([
                        'attempts' => DB::raw('LEAST(attempts + 1, 65535)'),
                        'available_at' => now()->addMinutes(2),
                        'claimed_by' => null, 'locked_until' => null,
                        'last_error' => mb_substr($e->getMessage(), 0, 500),
                    ]);
            }
        }
        return $handled;
    }
}
