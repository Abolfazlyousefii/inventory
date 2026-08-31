<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\CommissionLedgerEntry;
use App\Models\CommissionPeriod;
use App\Models\CommissionRateRevision;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PreinvoiceOrder;
use App\Models\Product;
use App\Models\User;
use App\Services\Commissions\CommissionCalculationService;
use App\Services\Commissions\CommissionHistoricalRateRepairService;
use App\Services\Commissions\CommissionRateService;
use App\Services\Commissions\CommissionTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Mockery;
use RuntimeException;
use Tests\TestCase;

class CommissionHistoricalRateRepairTest extends TestCase
{
    use RefreshDatabase;

    public function test_tree_aware_repair_backdates_exact_child_rates_and_repairs_parent_fallbacks(): void
    {
        $period = $this->period();
        $actor = User::factory()->create(['is_seller' => true]);
        $root = Category::query()->create(['name' => 'Root']);
        $cable = Category::query()->create(['name' => 'Cable', 'parent_id' => $root->id]);
        $adapter = Category::query()->create(['name' => 'Adapter', 'parent_id' => $root->id]);

        $rates = app(CommissionRateService::class);
        $rootRevision = $rates->setRate('category', $root->id, '1', $actor, '2026-08-16 10:00:00');
        $cableRevision = $rates->setRate('category', $cable->id, '3', $actor, '2026-08-16 14:00:00');
        $adapterRevision = $rates->setRate('category', $adapter->id, '1.5', $actor, '2026-08-16 14:00:00');

        $rootProduct = $this->product($root, 'Root product');
        $cableProduct = $this->product($cable, 'Cable product');
        $adapterProduct = $this->product($adapter, 'Adapter product');

        $rootInvoice = $this->invoice($actor, $rootProduct, '2026-08-05 12:00:00');
        $cableEarlyInvoice = $this->invoice($actor, $cableProduct, '2026-08-05 12:30:00');
        $adapterInvoice = $this->invoice($actor, $adapterProduct, '2026-08-05 13:00:00');
        $cableFallbackInvoice = $this->invoice($actor, $cableProduct, '2026-08-16 12:00:00');

        app(CommissionCalculationService::class)->recalculate($period);

        $this->assertTrue($this->ledgerFor($period, $rootInvoice)->missing_rate);
        $this->assertTrue($this->ledgerFor($period, $cableEarlyInvoice)->missing_rate);
        $this->assertTrue($this->ledgerFor($period, $adapterInvoice)->missing_rate);
        $fallback = $this->ledgerFor($period, $cableFallbackInvoice);
        $this->assertFalse($fallback->missing_rate);
        $this->assertSame('1.0000', $fallback->base_rate_snapshot);
        $this->assertSame($root->id, (int) $fallback->rate_source_id);

        $plan = app(CommissionHistoricalRateRepairService::class)->plan($period->fresh(), $root);
        $this->assertSame(3, $plan['summary']['repair_targets']);
        $this->assertSame(4, $plan['summary']['candidate_items']);
        $this->assertSame(3, $plan['summary']['historically_missing_items']);
        $this->assertSame(1, $plan['summary']['historical_fallback_items']);
        $this->assertSame(0, $plan['summary']['blocked_targets']);
        $this->assertSame(0, $plan['summary']['unresolved_items']);

        $targets = collect($plan['targets'])->keyBy('target_key');
        $this->assertSame('1.0000', $targets->get('category:'.$root->id)['percentage']);
        $this->assertSame('3.0000', $targets->get('category:'.$cable->id)['percentage']);
        $this->assertSame('1.5000', $targets->get('category:'.$adapter->id)['percentage']);
        $this->assertSame(1, $targets->get('category:'.$cable->id)['historical_fallback_items']);

        $this->artisan('commissions:repair-missing-rates', [
            '--period' => $period->id,
            '--category' => $root->id,
            '--apply' => true,
        ])->assertSuccessful();

        $this->assertSame('2026-08-01 00:00:00', $rootRevision->fresh()->effective_from->toDateTimeString());
        $this->assertSame('2026-08-01 00:00:00', $cableRevision->fresh()->effective_from->toDateTimeString());
        $this->assertSame('2026-08-01 00:00:00', $adapterRevision->fresh()->effective_from->toDateTimeString());

        $this->assertLedgerRate($period, $rootInvoice, '1.0000', $rootRevision->id, $root->id);
        $this->assertLedgerRate($period, $cableEarlyInvoice, '3.0000', $cableRevision->id, $cable->id);
        $this->assertLedgerRate($period, $cableFallbackInvoice, '3.0000', $cableRevision->id, $cable->id);
        $this->assertLedgerRate($period, $adapterInvoice, '1.5000', $adapterRevision->id, $adapter->id);
        $this->assertFalse($period->fresh()->needs_recalculation);
    }

    public function test_explicit_zero_child_rate_is_backdated_as_a_real_rate_not_as_missing(): void
    {
        $period = $this->period();
        $actor = User::factory()->create(['is_seller' => true]);
        $root = Category::query()->create(['name' => 'Root']);
        $zeroChild = Category::query()->create(['name' => 'Intentional zero', 'parent_id' => $root->id]);
        $product = $this->product($zeroChild, 'Zero commission product');

        app(CommissionRateService::class)->setRate('category', $root->id, '2', $actor, '2026-08-16 10:00:00');
        $zeroRevision = app(CommissionRateService::class)->setRate('category', $zeroChild->id, '0', $actor, '2026-08-16 11:00:00');
        $invoice = $this->invoice($actor, $product, '2026-08-05 12:00:00');
        app(CommissionCalculationService::class)->recalculate($period);
        $this->assertTrue($this->ledgerFor($period, $invoice)->missing_rate);

        $this->artisan('commissions:repair-missing-rates', [
            '--period' => $period->id,
            '--category' => $root->id,
            '--apply' => true,
        ])->assertSuccessful();

        $entry = $this->ledgerFor($period, $invoice);
        $this->assertFalse($entry->missing_rate);
        $this->assertSame('0.0000', $entry->base_rate_snapshot);
        $this->assertSame(0, $entry->base_commission_amount);
        $this->assertSame('category', $entry->rate_source_type);
        $this->assertSame($zeroChild->id, (int) $entry->rate_source_id);
        $this->assertSame($zeroRevision->id, (int) $entry->rate_rule_id);
    }

    public function test_unresolved_current_rate_blocks_the_entire_apply_before_any_target_is_mutated(): void
    {
        $period = $this->period();
        $actor = User::factory()->create(['is_seller' => true]);
        $root = Category::query()->create(['name' => 'Root without fallback']);
        $ratedChild = Category::query()->create(['name' => 'Rated child', 'parent_id' => $root->id]);
        $unratedChild = Category::query()->create(['name' => 'Unrated child', 'parent_id' => $root->id]);
        $ratedProduct = $this->product($ratedChild, 'Rated product');
        $unratedProduct = $this->product($unratedChild, 'Unrated product');
        $revision = app(CommissionRateService::class)->setRate('category', $ratedChild->id, '2', $actor, '2026-08-16 10:00:00');
        $ratedInvoice = $this->invoice($actor, $ratedProduct, '2026-08-05 12:00:00');
        $unratedInvoice = $this->invoice($actor, $unratedProduct, '2026-08-05 13:00:00');
        app(CommissionCalculationService::class)->recalculate($period);

        $plan = app(CommissionHistoricalRateRepairService::class)->plan($period->fresh(), $root);
        $this->assertSame(1, $plan['summary']['repair_targets']);
        $this->assertSame(1, $plan['summary']['unresolved_items']);
        $this->assertSame('NO_CURRENT_RATE', $plan['unresolved'][0]['reason']);

        $this->artisan('commissions:repair-missing-rates', [
            '--period' => $period->id,
            '--category' => $root->id,
            '--apply' => true,
        ])->assertFailed();

        $this->assertSame('2026-08-16 10:00:00', $revision->fresh()->effective_from->toDateTimeString());
        $this->assertTrue($this->ledgerFor($period, $ratedInvoice)->missing_rate);
        $this->assertTrue($this->ledgerFor($period, $unratedInvoice)->missing_rate);
    }

    public function test_existing_timeline_that_already_covers_period_start_is_preserved_as_intentional_history(): void
    {
        $period = $this->period();
        $actor = User::factory()->create(['is_seller' => true]);
        $root = Category::query()->create(['name' => 'Root']);
        $child = Category::query()->create(['name' => 'Changed rate child', 'parent_id' => $root->id]);
        $product = $this->product($child, 'Changed-rate product');
        $rates = app(CommissionRateService::class);
        $oldRevision = $rates->setRate('category', $child->id, '1', $actor, '2026-08-01 00:00:00');
        $newRevision = $rates->setRate('category', $child->id, '3', $actor, '2026-08-16 00:00:00');
        $invoice = $this->invoice($actor, $product, '2026-08-05 12:00:00');
        app(CommissionCalculationService::class)->recalculate($period);
        $this->assertSame('1.0000', $this->ledgerFor($period, $invoice)->base_rate_snapshot);

        $plan = app(CommissionHistoricalRateRepairService::class)->plan($period->fresh(), $root);
        $this->assertSame(0, $plan['summary']['repair_targets']);
        $this->assertSame(0, $plan['summary']['blocked_targets']);
        $this->assertSame(0, $plan['summary']['unresolved_items']);

        $this->artisan('commissions:repair-missing-rates', [
            '--period' => $period->id,
            '--category' => $root->id,
            '--apply' => true,
        ])->assertSuccessful();

        $this->assertSame('2026-08-01 00:00:00', $oldRevision->fresh()->effective_from->toDateTimeString());
        $this->assertSame('2026-08-16 00:00:00', $newRevision->fresh()->effective_from->toDateTimeString());
        $this->assertSame('1.0000', $this->ledgerFor($period, $invoice)->base_rate_snapshot);
    }

    public function test_timeline_aware_repair_backdates_the_inactive_leading_revision_and_preserves_later_transition(): void
    {
        $period = $this->period();
        $actor = User::factory()->create(['is_seller' => true]);
        $root = Category::query()->create(['name' => 'Timeline root']);
        $product = $this->product($root, 'Timeline product');
        $rates = app(CommissionRateService::class);

        $leading = $rates->setRate('category', $root->id, '1.5', $actor, '2026-08-13 22:56:04');
        $later = $rates->setRate('category', $root->id, '2', $actor, '2026-08-14 20:04:47');

        $beforeFirstRate = $this->invoice($actor, $product, '2026-08-05 12:00:00');
        $beforeTransition = $this->invoice($actor, $product, '2026-08-14 10:00:00');
        $afterTransition = $this->invoice($actor, $product, '2026-08-15 10:00:00');

        app(CommissionCalculationService::class)->recalculate($period);

        $this->assertTrue($this->ledgerFor($period, $beforeFirstRate)->missing_rate);
        $this->assertLedgerRate($period, $beforeTransition, '1.5000', $leading->id, $root->id);
        $this->assertLedgerRate($period, $afterTransition, '2.0000', $later->id, $root->id);

        $plan = app(CommissionHistoricalRateRepairService::class)->plan($period->fresh(), $root);
        $this->assertSame(1, $plan['summary']['repair_targets']);
        $this->assertSame(1, $plan['summary']['candidate_items']);
        $this->assertSame(1, $plan['summary']['historically_missing_items']);
        $this->assertSame(0, $plan['summary']['blocked_targets']);
        $this->assertSame(0, $plan['summary']['unresolved_items']);

        $target = collect($plan['targets'])->firstWhere('target_key', 'category:'.$root->id);
        $this->assertNotNull($target);
        $this->assertSame($leading->id, $target['revision_id']);
        $this->assertFalse($target['revision_is_active']);
        $this->assertSame('1.5000', $target['percentage']);
        $this->assertSame('2026-08-13 22:56:04', $target['current_effective_from']);
        $this->assertSame('2026-08-14 20:04:47', $target['current_effective_to']);

        $this->artisan('commissions:repair-missing-rates', [
            '--period' => $period->id,
            '--category' => $root->id,
            '--apply' => true,
        ])->assertSuccessful();

        $leading->refresh();
        $later->refresh();

        $this->assertSame('2026-08-01 00:00:00', $leading->effective_from->toDateTimeString());
        $this->assertSame('2026-08-14 20:04:47', $leading->effective_to->toDateTimeString());
        $this->assertNull($leading->active_marker);
        $this->assertSame('2026-08-14 20:04:47', $later->effective_from->toDateTimeString());
        $this->assertNull($later->effective_to);
        $this->assertSame(1, (int) $later->active_marker);

        $this->assertLedgerRate($period, $beforeFirstRate, '1.5000', $leading->id, $root->id);
        $this->assertLedgerRate($period, $beforeTransition, '1.5000', $leading->id, $root->id);
        $this->assertLedgerRate($period, $afterTransition, '2.0000', $later->id, $root->id);
        $this->assertFalse($period->fresh()->needs_recalculation);
    }

    public function test_outer_transaction_rolls_back_all_backdates_when_full_recalculation_fails(): void
    {
        $period = $this->period();
        $actor = User::factory()->create(['is_seller' => true]);
        $root = Category::query()->create(['name' => 'Root']);
        $child = Category::query()->create(['name' => 'Child', 'parent_id' => $root->id]);
        $rootProduct = $this->product($root, 'Root product');
        $childProduct = $this->product($child, 'Child product');
        $rootRevision = app(CommissionRateService::class)->setRate('category', $root->id, '1', $actor, '2026-08-16 10:00:00');
        $childRevision = app(CommissionRateService::class)->setRate('category', $child->id, '3', $actor, '2026-08-16 11:00:00');
        $this->invoice($actor, $rootProduct, '2026-08-05 11:00:00');
        $this->invoice($actor, $childProduct, '2026-08-05 12:00:00');

        $calculation = Mockery::mock(CommissionCalculationService::class);
        $calculation->shouldReceive('recalculate')->once()->andThrow(new RuntimeException('forced recalculation failure'));
        $this->app->instance(CommissionCalculationService::class, $calculation);

        try {
            app(CommissionHistoricalRateRepairService::class)->repair($period->fresh(), $root);
            $this->fail('Expected forced recalculation failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('forced recalculation failure', $exception->getMessage());
        }

        $this->assertSame('2026-08-16 10:00:00', $rootRevision->fresh()->effective_from->toDateTimeString());
        $this->assertSame('2026-08-16 11:00:00', $childRevision->fresh()->effective_from->toDateTimeString());
    }

    public function test_timeline_repair_rolls_back_inactive_revision_backdate_and_preserves_transition_when_recalculation_fails(): void
    {
        $period = $this->period();
        $actor = User::factory()->create(['is_seller' => true]);
        $root = Category::query()->create(['name' => 'Rollback timeline root']);
        $product = $this->product($root, 'Rollback timeline product');
        $rates = app(CommissionRateService::class);
        $leading = $rates->setRate('category', $root->id, '1.5', $actor, '2026-08-13 10:00:00');
        $later = $rates->setRate('category', $root->id, '2', $actor, '2026-08-14 10:00:00');
        $this->invoice($actor, $product, '2026-08-05 12:00:00');

        $calculation = Mockery::mock(CommissionCalculationService::class);
        $calculation->shouldReceive('recalculate')->once()->andThrow(new RuntimeException('forced timeline recalculation failure'));
        $this->app->instance(CommissionCalculationService::class, $calculation);

        try {
            app(CommissionHistoricalRateRepairService::class)->repair($period->fresh(), $root);
            $this->fail('Expected forced timeline recalculation failure.');
        } catch (RuntimeException $exception) {
            $this->assertSame('forced timeline recalculation failure', $exception->getMessage());
        }

        $leading->refresh();
        $later->refresh();
        $this->assertSame('2026-08-13 10:00:00', $leading->effective_from->toDateTimeString());
        $this->assertSame('2026-08-14 10:00:00', $leading->effective_to->toDateTimeString());
        $this->assertNull($leading->active_marker);
        $this->assertSame('2026-08-14 10:00:00', $later->effective_from->toDateTimeString());
        $this->assertSame(1, (int) $later->active_marker);
    }

    public function test_apply_with_seller_filter_and_conflicting_dry_run_apply_flags_are_fail_closed(): void
    {
        $period = $this->period();
        $seller = User::factory()->create(['is_seller' => true]);
        $root = Category::query()->create(['name' => 'Root']);
        $product = $this->product($root, 'Product');
        $revision = app(CommissionRateService::class)->setRate('category', $root->id, '2', $seller, '2026-08-16 10:00:00');
        $this->invoice($seller, $product, '2026-08-05 12:00:00');

        $this->artisan('commissions:repair-missing-rates', [
            '--period' => $period->id,
            '--category' => $root->id,
            '--seller' => $seller->id,
            '--apply' => true,
        ])->assertFailed();

        $this->artisan('commissions:repair-missing-rates', [
            '--period' => $period->id,
            '--category' => $root->id,
            '--dry-run' => true,
            '--apply' => true,
        ])->assertFailed();

        $this->assertSame('2026-08-16 10:00:00', $revision->fresh()->effective_from->toDateTimeString());
    }

    public function test_expected_revision_guard_rejects_a_rate_that_changed_after_preview(): void
    {
        $actor = User::factory()->create();
        $root = Category::query()->create(['name' => 'Root']);
        $rates = app(CommissionRateService::class);
        $previewed = $rates->setRate('category', $root->id, '1', $actor, '2026-08-16 10:00:00');
        $current = $rates->setRate('category', $root->id, '2', $actor, '2026-08-20 10:00:00');

        try {
            $rates->backdateActiveRate('category', $root->id, '2026-08-01 00:00:00', $actor, $previewed->id);
            $this->fail('Expected stale preview guard to reject the repair.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('rate', $exception->errors());
        }

        $this->assertSame('2026-08-20 10:00:00', $current->fresh()->effective_from->toDateTimeString());
    }

    public function test_historical_revision_backdate_rejects_a_stale_preview_without_touching_later_revision(): void
    {
        $actor = User::factory()->create();
        $root = Category::query()->create(['name' => 'Root']);
        $rates = app(CommissionRateService::class);
        $leading = $rates->setRate('category', $root->id, '1.5', $actor, '2026-08-13 10:00:00');
        $later = $rates->setRate('category', $root->id, '2', $actor, '2026-08-14 10:00:00');

        // Simulate a legitimate change after preview was generated.
        $leading->update(['effective_from' => '2026-08-12 10:00:00']);

        try {
            $rates->backdateRevision(
                $leading->id,
                '2026-08-01 00:00:00',
                $actor,
                '2026-08-13 10:00:00',
            );
            $this->fail('Expected stale timeline preview guard to reject the repair.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('rate', $exception->errors());
        }

        $this->assertSame('2026-08-12 10:00:00', $leading->fresh()->effective_from->toDateTimeString());
        $this->assertSame('2026-08-14 10:00:00', $leading->fresh()->effective_to->toDateTimeString());
        $this->assertSame('2026-08-14 10:00:00', $later->fresh()->effective_from->toDateTimeString());
        $this->assertSame(1, (int) $later->fresh()->active_marker);
    }

    public function test_second_apply_is_a_noop_and_does_not_create_duplicate_active_ledger_rows(): void
    {
        $period = $this->period();
        $actor = User::factory()->create(['is_seller' => true]);
        $root = Category::query()->create(['name' => 'Root']);
        $product = $this->product($root, 'Product');
        app(CommissionRateService::class)->setRate('category', $root->id, '2', $actor, '2026-08-16 10:00:00');
        $invoice = $this->invoice($actor, $product, '2026-08-05 12:00:00');
        app(CommissionCalculationService::class)->recalculate($period);

        $arguments = ['--period' => $period->id, '--category' => $root->id, '--apply' => true];
        $this->artisan('commissions:repair-missing-rates', $arguments)->assertSuccessful();
        $totalLedgerRows = CommissionLedgerEntry::query()->count();
        $activeLedgerRows = CommissionLedgerEntry::query()->where('active_marker', 1)->count();

        $this->artisan('commissions:repair-missing-rates', $arguments)->assertSuccessful();

        $this->assertSame($totalLedgerRows, CommissionLedgerEntry::query()->count());
        $this->assertSame($activeLedgerRows, CommissionLedgerEntry::query()->where('active_marker', 1)->count());
        $this->assertFalse($this->ledgerFor($period, $invoice)->missing_rate);
    }

    private function period(string $status = CommissionPeriod::STATUS_OPEN): CommissionPeriod
    {
        return CommissionPeriod::query()->create([
            'label' => 'Phase 1 '.Str::uuid(),
            'start_at' => '2026-08-01 00:00:00',
            'end_at' => '2026-09-01 00:00:00',
            'cycle_day_snapshot' => 10,
            'status' => $status,
            'needs_recalculation' => false,
        ]);
    }

    private function product(Category $category, string $name): Product
    {
        return Product::query()->create([
            'name' => $name,
            'sku' => (string) Str::uuid(),
            'category_id' => $category->id,
            'stock' => 1,
            'reserved' => 0,
            'price' => 10_000_000,
        ]);
    }

    private function invoice(User $seller, Product $product, string $date): Invoice
    {
        $preinvoice = PreinvoiceOrder::query()->create([
            'uuid' => (string) Str::uuid(),
            'created_by' => $seller->id,
            'seller_id' => $seller->id,
            'customer_name' => 'Customer',
            'customer_mobile' => '09120000000',
            'customer_address' => 'Tehran',
            'province_id' => 1,
            'shipping_id' => 0,
            'shipping_price' => 0,
            'discount_amount' => 0,
            'total_price' => 10_000_000,
            'status' => PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE,
        ]);

        $invoice = Invoice::query()->create([
            'uuid' => (string) Str::uuid(),
            'preinvoice_order_id' => $preinvoice->id,
            'customer_name' => 'Customer',
            'document_date' => $date,
            'shipping_price' => 0,
            'discount_amount' => 0,
            'invoice_discount_amount' => 0,
            'product_discount_amount' => 0,
            'discount_allocation_mode' => 'separate',
            'subtotal' => 10_000_000,
            'total' => 10_000_000,
            'status' => Invoice::STATUS_SHIPPED,
        ]);

        InvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'quantity' => 1,
            'price' => 10_000_000,
            'line_discount_amount' => 0,
        ]);

        return $invoice->fresh('items');
    }

    private function ledgerFor(CommissionPeriod $period, Invoice $invoice): CommissionLedgerEntry
    {
        return CommissionLedgerEntry::query()
            ->where('commission_period_id', $period->id)
            ->where('invoice_id', $invoice->id)
            ->where('active_marker', 1)
            ->firstOrFail();
    }

    private function assertLedgerRate(CommissionPeriod $period, Invoice $invoice, string $percentage, int $ruleId, int $sourceId): void
    {
        $entry = $this->ledgerFor($period, $invoice);
        $this->assertFalse($entry->missing_rate);
        $this->assertSame($percentage, $entry->base_rate_snapshot);
        $this->assertSame('category', $entry->rate_source_type);
        $this->assertSame($sourceId, (int) $entry->rate_source_id);
        $this->assertSame($ruleId, (int) $entry->rate_rule_id);
    }
}
