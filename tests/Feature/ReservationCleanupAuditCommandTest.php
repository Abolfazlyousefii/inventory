<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\PreinvoiceDraftReservation;
use App\Models\Product;
use App\Models\ProductVariant;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\TestCase;

class ReservationCleanupAuditCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_is_read_only_and_reports_ambiguous_null_preinvoice(): void
    {
        Storage::fake('local');
        $category = Category::withoutEvents(fn () => Category::query()->create(['name' => 'Audit '.Str::uuid()]));
        $product = Product::withoutEvents(fn () => Product::query()->create([
            'category_id' => $category->id, 'name' => 'Audit product', 'sku' => 'AUD-'.Str::uuid(),
            'stock' => 10, 'reserved' => 4, 'price' => 100, 'is_sellable' => true,
        ]));
        $variant = ProductVariant::withoutEvents(fn () => ProductVariant::query()->create([
            'product_id' => $product->id, 'variant_name' => 'Audit variant', 'variant_code' => 'AUD-V-'.Str::uuid(),
            'stock' => 10, 'reserved' => 4, 'sell_price' => 100, 'is_active' => true, 'sales_enabled' => true,
        ]));
        $reservation = PreinvoiceDraftReservation::query()->create([
            'token' => (string) Str::uuid(), 'product_id' => $product->id, 'variant_id' => $variant->id,
            'quantity' => 4, 'last_seen_at' => now()->subDays(10), 'expires_at' => now()->subDays(10),
        ]);
        DB::table('preinvoice_draft_reservations')->whereKey($reservation->id)->update([
            'created_at' => now()->subDays(10), 'updated_at' => now()->subDays(10),
        ]);
        $writes = [];
        DB::listen(function ($event) use (&$writes): void {
            if (preg_match('/^\s*(insert|update|delete|replace|truncate)\b/i', $event->sql)) $writes[] = $event->sql;
        });
        $writes = [];

        $this->artisan("inventory:audit-reservation-cleanup --reservation={$reservation->id}")->assertSuccessful();

        $this->assertSame([], $writes);
        Storage::disk('local')->assertExists('reports/reservation-cleanup-audit/reservations.csv');
        $json = json_decode(Storage::disk('local')->get('reports/reservation-cleanup-audit/summary.json'), true);
        $this->assertSame('historical_ambiguous', $json['rows'][0]['classification']);
        $this->assertSame('manual_review', $json['rows'][0]['recommended_action']);
        $this->assertFalse($json['rows'][0]['would_change_warehouse_stock']);
    }
}
