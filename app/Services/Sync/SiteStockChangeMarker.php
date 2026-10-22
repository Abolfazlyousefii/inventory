<?php

namespace App\Services\Sync;

use Illuminate\Support\Facades\DB;

/** Durable event, written in the same Inventory transaction as the mutation. */
final class SiteStockChangeMarker
{
    public static function mark(int $productId, int $variantId = 0): void
    {
        if (!config('services.request_driven_site_stock', false) || $productId < 1) return;
        DB::table('site_stock_sync_outbox')->insert([
            'product_id' => $productId,
            'variant_id' => max(0, $variantId), 'origin' => 'inventory',
            'created_at' => now(), 'available_at' => now(),
        ]);
    }
}
