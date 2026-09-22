<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\PreinvoiceDraftReservation;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\WarehouseStock;
use App\Services\HistoricalReservationArchiveService;
use App\Services\WarehouseStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class ArchiveHistoricalReservationsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_command_rejects_unsafe_flag_and_selector_combinations(): void
    {
        $this->artisan('inventory:archive-historical-reservations')->assertFailed();
        $this->artisan('inventory:archive-historical-reservations --ids=1 --all-historical')->assertFailed();
        $this->artisan('inventory:archive-historical-reservations --apply --ids=1')->assertFailed();
        $this->artisan('inventory:archive-historical-reservations --confirm --ids=1')->assertFailed();
    }

    public function test_dry_run_is_default_and_writes_nothing(): void
    {
        $historical = $this->reservation(old: true);
        $before = DB::table('preinvoice_draft_reservations')->where('id', $historical->id)->first();

        $this->artisan("inventory:archive-historical-reservations --ids={$historical->id}")
            ->expectsOutputToContain('NO DATA CHANGED')
            ->assertSuccessful();
        $this->artisan("inventory:archive-historical-reservations --dry-run --ids={$historical->id}")
            ->expectsOutputToContain('Eligible: 1')
            ->assertSuccessful();

        $this->assertEquals($before, DB::table('preinvoice_draft_reservations')->where('id', $historical->id)->first());
    }

    public function test_confirmed_apply_archives_only_historical_rows_from_mixed_ids(): void
    {
        $historical = $this->reservation(old: true);
        $active = $this->reservation(old: false);
        $ids = implode(',', [$historical->id, $historical->id, $active->id, 999999]);

        $this->artisan("inventory:archive-historical-reservations --apply --confirm --ids={$ids}")
            ->expectsOutputToContain('Archived: 1')
            ->assertSuccessful();

        $this->assertSame(HistoricalReservationArchiveService::RELEASE_REASON, $historical->fresh()->release_reason);
        $this->assertNull($active->fresh()->released_at);
    }

    public function test_all_historical_archives_every_and_only_canonical_historical_row(): void
    {
        $first = $this->reservation(old: true);
        $second = $this->reservation(old: true);
        $active = $this->reservation(old: false);

        $this->artisan('inventory:archive-historical-reservations --apply --confirm --all-historical')
            ->expectsOutputToContain('Archived: 2')
            ->assertSuccessful();

        $this->assertSame(HistoricalReservationArchiveService::RELEASE_REASON, $first->fresh()->release_reason);
        $this->assertSame(HistoricalReservationArchiveService::RELEASE_REASON, $second->fresh()->release_reason);
        $this->assertNull($active->fresh()->released_at);
    }

    private function reservation(bool $old): PreinvoiceDraftReservation
    {
        $category = Category::withoutEvents(fn () => Category::query()->create(['name' => 'Archive command '.Str::uuid()]));
        $product = Product::withoutEvents(fn () => Product::query()->create([
            'category_id' => $category->id, 'name' => 'Archive command product', 'sku' => 'ARCH-CMD-'.Str::uuid(),
            'stock' => 100, 'reserved' => 0, 'price' => 1000, 'is_sellable' => true,
        ]));
        $variant = ProductVariant::withoutEvents(fn () => ProductVariant::query()->create([
            'product_id' => $product->id, 'variant_name' => 'Archive command variant', 'variant_code' => 'ARCH-CMD-V-'.Str::uuid(),
            'stock' => 100, 'reserved' => 0, 'sell_price' => 1000, 'is_active' => true, 'sales_enabled' => true,
        ]));
        WarehouseStock::withoutEvents(fn () => WarehouseStock::query()->create([
            'warehouse_id' => WarehouseStockService::centralWarehouseId(), 'product_id' => $product->id,
            'product_variant_id' => $variant->id, 'quantity' => 100,
        ]));
        $reservation = PreinvoiceDraftReservation::query()->create([
            'token' => (string) Str::uuid(), 'product_id' => $product->id, 'variant_id' => $variant->id,
            'quantity' => 5, 'reservation_scope' => PreinvoiceDraftReservation::SCOPE_TEMPORARY_ONLINE,
            'last_seen_at' => $old ? now()->subDays(10) : now(),
            'expires_at' => $old ? now()->subDays(10) : now()->addHour(),
        ]);
        if ($old) {
            DB::table('preinvoice_draft_reservations')->where('id', $reservation->id)->update([
                'created_at' => now()->subDays(10), 'updated_at' => now()->subDays(10),
            ]);
        }

        return $reservation->refresh();
    }
}
