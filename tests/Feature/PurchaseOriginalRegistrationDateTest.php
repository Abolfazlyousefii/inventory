<?php

namespace Tests\Feature;

use App\Exports\PurchasesExport;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\User;
use App\Support\JalaliDate;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PurchaseOriginalRegistrationDateTest extends TestCase
{
    use RefreshDatabase;

    public function test_list_details_excel_and_date_filter_use_first_registration_even_if_purchase_date_has_changed(): void
    {
        $owner = User::factory()->create();
        $owner->assignRole(Role::findOrCreate('Owner', 'web'));
        $this->actingAs($owner);

        $this->travelTo(Carbon::parse('2026-07-19 08:30:00'));
        $supplier = Supplier::query()->create(['name' => 'Original purchase date supplier']);
        $purchase = Purchase::query()->create([
            'supplier_id' => $supplier->id,
            'purchased_at' => '2026-07-19 08:30:00',
            'total_amount' => 100000,
            'note' => 'Initial document',
        ]);

        $firstRegistration = $purchase->created_at->copy();
        $this->travelTo(Carbon::parse('2026-10-15 17:40:00'));
        // Simulate a legacy purchase that had its mutable purchased_at changed.
        $purchase->update([
            'purchased_at' => '2026-10-15 17:40:00',
            'note' => 'Updated purchase',
        ]);

        $purchase->refresh();
        $this->assertTrue($purchase->created_at->equalTo($firstRegistration));

        $registeredJalali = JalaliDate::dateTime($firstRegistration);
        $this->get(route('purchases.index'))
            ->assertOk()
            ->assertSee($registeredJalali)
            ->assertDontSee(JalaliDate::dateTime($purchase->purchased_at));

        $this->get(route('purchases.show', $purchase))
            ->assertOk()
            ->assertSee($registeredJalali);

        $export = new PurchasesExport([
            'date_from' => '2026-07-19',
            'date_to' => '2026-07-19',
        ]);
        $this->assertSame([$purchase->id], $export->query()->pluck('id')->all());
        $this->assertSame($registeredJalali, $export->map($purchase)[1]);

        $this->get(route('purchases.index', [
            'date_from' => '2026-07-19',
            'date_to' => '2026-07-19',
        ]))->assertOk()->assertSee('Updated purchase');
        $this->get(route('purchases.index', [
            'date_from' => '2026-10-15',
            'date_to' => '2026-10-15',
        ]))->assertOk()->assertDontSee('Updated purchase');
    }

    public function test_edit_form_displays_original_registration_date_without_an_editable_date_field(): void
    {
        $blade = file_get_contents(resource_path('views/purchases/create.blade.php'));
        $this->assertStringContainsString('تاریخ ثبت اولیه', $blade);
        $this->assertStringContainsString('$purchase->created_at', $blade);
        $this->assertStringNotContainsString('name="purchased_at"', $blade);
    }
}
