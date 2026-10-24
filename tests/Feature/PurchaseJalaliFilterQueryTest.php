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

        // Registration date (created_at), not editable purchased_at, defines filter results.
        $createAt = function (Supplier $supplier, string $timestamp, int $amount): Purchase {
            $this->travelTo(\Carbon\Carbon::parse($timestamp));

            return Purchase::query()->create([
                'supplier_id' => $supplier->id,
                'purchased_at' => $timestamp,
                'total_amount' => $amount,
            ]);
        };

        $createAt($selectedSupplier, '2026-07-18 23:59:59', 1);
        $first = $createAt($selectedSupplier, '2026-07-19 00:00:00', 2);
        $last = $createAt($selectedSupplier, '2026-07-20 23:59:59', 3);
        $createAt($selectedSupplier, '2026-07-21 00:00:00', 4);
        $createAt($otherSupplier, '2026-07-20 12:00:00', 5);
        $this->travelBack();

        $rows = (new PurchasesExport([
            'supplier_id' => $selectedSupplier->id,
            'date_from' => '2026-07-19',
            'date_to' => '2026-07-20',
        ]))->query()->get();

        $this->assertSame([$first->id, $last->id], $rows->pluck('id')->all());
    }
}
