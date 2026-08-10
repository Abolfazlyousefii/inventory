<?php

namespace Tests\Feature;

use App\Exports\PurchasesExport;
use App\Models\Purchase;
use App\Models\Supplier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PurchaseJalaliFilterQueryTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_filter_includes_the_whole_end_day_and_combines_supplier_filter(): void
    {
        $selectedSupplier = Supplier::query()->create(['name' => 'تأمین‌کننده منتخب']);
        $otherSupplier = Supplier::query()->create(['name' => 'تأمین‌کننده دیگر']);

        Purchase::query()->create(['supplier_id' => $selectedSupplier->id, 'purchased_at' => '2026-07-18 23:59:59', 'total_amount' => 1]);
        $first = Purchase::query()->create(['supplier_id' => $selectedSupplier->id, 'purchased_at' => '2026-07-19 00:00:00', 'total_amount' => 2]);
        $last = Purchase::query()->create(['supplier_id' => $selectedSupplier->id, 'purchased_at' => '2026-07-20 23:59:59', 'total_amount' => 3]);
        Purchase::query()->create(['supplier_id' => $selectedSupplier->id, 'purchased_at' => '2026-07-21 00:00:00', 'total_amount' => 4]);
        Purchase::query()->create(['supplier_id' => $otherSupplier->id, 'purchased_at' => '2026-07-20 12:00:00', 'total_amount' => 5]);

        $rows = (new PurchasesExport([
            'supplier_id' => $selectedSupplier->id,
            'date_from' => '2026-07-19',
            'date_to' => '2026-07-20',
        ]))->query()->get();

        $this->assertSame([$first->id, $last->id], $rows->pluck('id')->all());
    }
}
