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
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Commissions\CommissionCalculationService;
use App\Services\Commissions\CommissionRateAuditService;
use App\Services\Commissions\CommissionRateResolver;
use App\Services\Commissions\CommissionRateService;
use App\Services\Commissions\CommissionRateTreeService;
use App\Services\Commissions\CommissionTarget;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CommissionRateCoverageRegressionTest extends TestCase
{
    use RefreshDatabase;

    public function test_category_product_pagination_returns_all_unique_products_and_categories_only_once(): void
    {
        $parent = Category::query()->create(['name' => 'Guard']);
        Category::query()->create(['name' => 'Child', 'parent_id' => $parent->id]);
        foreach (range(1, 75) as $index) $this->product($parent, 'Product '.str_pad((string) $index, 3, '0', STR_PAD_LEFT));

        $service = app(CommissionRateTreeService::class);
        $pages = collect([1, 2, 3])->map(fn ($page) => $service->children('category', $parent->id, '', $page));
        $products = $pages->flatMap(fn ($payload) => collect($payload['items'])->where('type', 'product'));

        $this->assertSame([30, 30, 15], $pages->map(fn ($payload) => collect($payload['items'])->where('type', 'product')->count())->all());
        $this->assertSame([true, true, false], $pages->pluck('has_more')->all());
        $this->assertSame([2, 3, null], $pages->pluck('next_page')->all());
        $this->assertCount(75, $products);
        $this->assertCount(75, $products->pluck('id')->unique());
        $this->assertSame(1, $pages->flatMap(fn ($payload) => $payload['items'])->where('type', 'category')->count());
    }

    public function test_variant_pagination_and_frontend_load_more_contract_are_complete(): void
    {
        $category = Category::query()->create(['name' => 'Variants']);
        $product = $this->product($category, 'Phone');
        foreach (range(1, 65) as $index) ProductVariant::query()->create(['product_id' => $product->id, 'variant_name' => 'Variant '.$index, 'variant_code' => 'V'.$index, 'stock' => 1, 'reserved' => 0, 'sell_price' => 1000]);
        $service = app(CommissionRateTreeService::class);
        $pages = collect([1, 2, 3])->map(fn ($page) => $service->children('product', $product->id, '', $page));

        $this->assertSame([30, 30, 5], $pages->map(fn ($payload) => count($payload['items']))->all());
        $js = file_get_contents(public_path('js/commissions.js'));
        $this->assertStringContainsString('commission-load-more', $js);
        $this->assertStringContainsString('page: loadMoreButton.dataset.page', $js);
        $this->assertStringContainsString('commission-search-limited', $js);
        $view = file_get_contents(resource_path('views/commercial/commissions/index.blade.php'));
        $this->assertStringContainsString('name="effective_mode" value="period_start"', $view);
        $this->assertStringContainsString('name="effective_mode" value="today"', $view);
        $this->assertStringContainsString('name="effective_mode" value="custom"', $view);
    }

    public function test_search_reports_when_more_than_fifteen_results_are_available(): void
    {
        $category = Category::query()->create(['name' => 'Search Root']);
        foreach (range(1, 20) as $index) $this->product($category, 'Needle '.$index);
        $payload = app(CommissionRateTreeService::class)->search('Needle');

        $this->assertCount(15, collect($payload['items'])->where('type', 'product'));
        $this->assertTrue($payload['has_more']);
        $this->assertTrue($payload['is_limited']);
    }

    public function test_nested_category_inheritance_and_product_variant_overrides_preserve_explicit_zero(): void
    {
        $actor = User::factory()->create();
        $root = Category::query()->create(['name' => 'Guard']);
        $child = Category::query()->create(['name' => 'Samsung', 'parent_id' => $root->id]);
        $leaf = Category::query()->create(['name' => 'S25', 'parent_id' => $child->id]);
        $product = $this->product($leaf, 'Product');
        $variant = ProductVariant::query()->create(['product_id' => $product->id, 'variant_name' => 'Black', 'variant_code' => 'BLACK', 'stock' => 1, 'reserved' => 0, 'sell_price' => 1000]);
        $service = app(CommissionRateService::class);
        $resolver = app(CommissionRateResolver::class);
        $service->setRate('category', $root->id, '2', $actor, '2026-08-01');
        $this->assertSame('2.0000', $resolver->resolve($product, $variant, '2026-08-10')->percentage);
        $service->setRate('product', $product->id, '3', $actor, '2026-08-01');
        $this->assertSame('3.0000', $resolver->resolve($product, $variant, '2026-08-10')->percentage);
        $service->setRate('variant', $variant->id, '4', $actor, '2026-08-01');
        $this->assertSame('4.0000', $resolver->resolve($product, $variant, '2026-08-10')->percentage);
        $zeroProduct = $this->product($leaf, 'Zero Product');
        $service->setRate('product', $zeroProduct->id, '0', $actor, '2026-08-01');
        $zero = $resolver->resolve($zeroProduct, null, '2026-08-10');
        $this->assertFalse($zero->isMissing);
        $this->assertTrue($zero->isExplicitZero);
    }

    public function test_resolver_remains_historical_and_period_start_rate_covers_earlier_invoice(): void
    {
        $actor = User::factory()->create();
        $category = Category::query()->create(['name' => 'Guard']);
        $product = $this->product($category, 'Product');
        $service = app(CommissionRateService::class);
        $resolver = app(CommissionRateResolver::class);
        $service->setRate('category', $category->id, '2', $actor, '2026-08-18');
        $this->assertTrue($resolver->resolve($product, null, '2026-08-12')->isMissing);

        $other = Category::query()->create(['name' => 'Backdated Guard']);
        $otherProduct = $this->product($other, 'Covered Product');
        $service->setRate('category', $other->id, '2', $actor, '2026-08-01');
        $covered = $resolver->resolve($otherProduct, null, '2026-08-12');
        $this->assertFalse($covered->isMissing);
        $this->assertSame('2.0000', $covered->percentage);
    }

    public function test_timeline_conflict_blocks_backdate_without_mutation(): void
    {
        $actor = User::factory()->create();
        $category = Category::query()->create(['name' => 'Guard']);
        $key = CommissionTarget::key('category', $category->id);
        CommissionRateRevision::query()->create(array_merge(['target_type' => 'category', 'target_id' => $category->id, 'target_key' => $key, 'percentage' => '1', 'effective_from' => '2026-08-01', 'effective_to' => '2026-08-15', 'created_by' => $actor->id], CommissionTarget::foreignKeys('category', $category->id)));
        $active = CommissionRateRevision::query()->create(array_merge(['target_type' => 'category', 'target_id' => $category->id, 'target_key' => $key, 'active_marker' => 1, 'percentage' => '2', 'effective_from' => '2026-08-15', 'created_by' => $actor->id], CommissionTarget::foreignKeys('category', $category->id)));

        try {
            app(CommissionRateService::class)->backdateActiveRate('category', $category->id, '2026-08-10', $actor);
            $this->fail('Expected timeline conflict.');
        } catch (ValidationException) {
            $this->assertSame('2026-08-15 00:00:00', $active->fresh()->effective_from->toDateTimeString());
            $this->assertSame(2, CommissionRateRevision::query()->where('target_key', $key)->count());
        }
    }

    public function test_repair_dry_run_is_read_only_and_audit_identifies_late_rate(): void
    {
        [$period, $category, $product, $actor] = $this->lateRateScenario();
        $before = [
            'revision' => CommissionRateRevision::query()->firstOrFail()->toArray(),
            'period' => $period->fresh()->toArray(),
            'ledger' => CommissionLedgerEntry::query()->orderBy('id')->get()->toArray(),
        ];
        $this->artisan('commissions:repair-missing-rates', ['--period' => $period->id, '--category' => $category->id, '--dry-run' => true])->assertSuccessful();
        $this->assertSame($before, [
            'revision' => CommissionRateRevision::query()->firstOrFail()->toArray(),
            'period' => $period->fresh()->toArray(),
            'ledger' => CommissionLedgerEntry::query()->orderBy('id')->get()->toArray(),
        ]);
        $report = app(CommissionRateAuditService::class)->audit($period, $category);
        $row = collect($report['rows'])->firstWhere('product_id', $product->id);
        $this->assertSame('CURRENT_RATE_EXISTS_BUT_STARTED_LATE', $row['classification']);
        $this->assertSame(1, $row['missing_rate_ledger_count']);
    }

    public function test_repair_apply_backdates_recalculates_and_is_idempotent(): void
    {
        [$period, $category] = $this->lateRateScenario();
        $this->artisan('commissions:repair-missing-rates', ['--period' => $period->id, '--category' => $category->id, '--apply' => true])->assertSuccessful();
        $entry = CommissionLedgerEntry::query()->where('active_marker', 1)->firstOrFail();
        $this->assertFalse($entry->missing_rate);
        $this->assertSame('2.0000', $entry->base_rate_snapshot);
        $this->assertSame('category', $entry->rate_source_type);
        $this->assertSame($category->id, $entry->rate_source_id);
        $this->assertFalse($period->fresh()->needs_recalculation);
        $count = CommissionLedgerEntry::query()->where('active_marker', 1)->count();
        app(CommissionCalculationService::class)->recalculate($period->fresh());
        $this->assertSame($count, CommissionLedgerEntry::query()->where('active_marker', 1)->count());
    }

    public function test_repair_refuses_closed_and_paid_periods(): void
    {
        $period = $this->period();
        $category = Category::query()->create(['name' => 'Guard final']);
        $actor = User::factory()->create();
        app(CommissionRateService::class)->setRate('category', $category->id, '2', $actor, '2026-08-18');
        foreach ([CommissionPeriod::STATUS_CLOSED, CommissionPeriod::STATUS_PAID] as $status) {
            $period->update(['status' => $status]);
            $this->artisan('commissions:repair-missing-rates', ['--period' => $period->id, '--category' => $category->id, '--apply' => true])->assertFailed();
            $this->assertSame('2026-08-18 00:00:00', CommissionRateRevision::query()->where('category_id', $category->id)->firstOrFail()->effective_from->toDateTimeString());
        }
    }

    public function test_rate_mutation_marks_mutable_period_for_recalculation(): void
    {
        $period = $this->period();
        $category = Category::query()->create(['name' => 'Guard']);
        app(CommissionRateService::class)->setRate('category', $category->id, '2', User::factory()->create(), $period->start_at);
        $this->assertTrue($period->fresh()->needs_recalculation);
    }

    private function lateRateScenario(): array
    {
        $period = $this->period();
        $category = Category::query()->create(['name' => 'Guard']);
        $product = $this->product($category, 'Product');
        $actor = User::factory()->create(['is_seller' => true]);
        app(CommissionRateService::class)->setRate('category', $category->id, '2', $actor, '2026-08-18');
        $this->invoice($actor, $product, '2026-08-12 12:00:00');
        app(CommissionCalculationService::class)->recalculate($period);

        return [$period, $category, $product, $actor];
    }

    private function period(string $status = CommissionPeriod::STATUS_OPEN): CommissionPeriod
    {
        return CommissionPeriod::query()->create(['label' => 'Period '.Str::uuid(), 'start_at' => '2026-08-01', 'end_at' => '2026-09-01', 'cycle_day_snapshot' => 10, 'status' => $status]);
    }

    private function product(Category $category, string $name): Product
    {
        return Product::query()->create(['name' => $name, 'sku' => (string) Str::uuid(), 'category_id' => $category->id, 'stock' => 1, 'reserved' => 0, 'price' => 10_000_000]);
    }

    private function invoice(User $seller, Product $product, string $date): Invoice
    {
        $preinvoice = PreinvoiceOrder::query()->create(['uuid' => (string) Str::uuid(), 'created_by' => $seller->id, 'seller_id' => $seller->id, 'customer_name' => 'Customer', 'customer_mobile' => '09120000000', 'customer_address' => 'Tehran', 'province_id' => 1, 'shipping_id' => 0, 'shipping_price' => 0, 'discount_amount' => 0, 'total_price' => 10_000_000, 'status' => PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE]);
        $invoice = Invoice::query()->create(['uuid' => (string) Str::uuid(), 'preinvoice_order_id' => $preinvoice->id, 'customer_name' => 'Customer', 'document_date' => $date, 'shipping_price' => 0, 'discount_amount' => 0, 'invoice_discount_amount' => 0, 'product_discount_amount' => 0, 'discount_allocation_mode' => 'separate', 'subtotal' => 10_000_000, 'total' => 10_000_000, 'status' => Invoice::STATUS_SHIPPED]);
        InvoiceItem::query()->create(['invoice_id' => $invoice->id, 'product_id' => $product->id, 'quantity' => 1, 'price' => 10_000_000, 'line_discount_amount' => 0]);

        return $invoice->fresh('items');
    }
}
