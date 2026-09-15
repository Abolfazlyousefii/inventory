<?php

namespace Tests\Unit\Finance;

use App\Models\Category;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PreinvoiceOrder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\Commissions\CommissionRateResolver;
use App\Services\Commissions\CommissionRateService;
use App\Services\Finance\SellerCommissionBasePreviewCalculator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class SellerCommissionBasePreviewCalculatorTest extends TestCase
{
    use RefreshDatabase;

    private function calculator(): SellerCommissionBasePreviewCalculator
    {
        return app(SellerCommissionBasePreviewCalculator::class);
    }

    private function actor(): User
    {
        return User::factory()->create();
    }

    private function category(?int $parentId = null, string $name = 'دسته'): Category
    {
        return Category::query()->create(['name' => $name.'-'.Str::random(6), 'parent_id' => $parentId]);
    }

    private function product(int $categoryId, string $name = 'کالا'): Product
    {
        return Product::query()->create([
            'name' => $name, 'sku' => 'SKU-'.Str::random(8), 'category_id' => $categoryId,
            'stock' => 100, 'reserved' => 0, 'price' => 1000,
        ]);
    }

    private function variant(int $productId, string $name = 'تنوع'): ProductVariant
    {
        return ProductVariant::query()->create([
            'product_id' => $productId, 'variant_name' => $name, 'variant_code' => 'VAR-'.Str::random(8),
            'is_active' => true, 'sales_enabled' => true, 'stock' => 100, 'reserved' => 0, 'sell_price' => 1000,
        ]);
    }

    private function invoice(int $total, ?string $documentDate, string $createdAt, array $overrides = []): Invoice
    {
        $preinvoice = PreinvoiceOrder::query()->create([
            'uuid' => (string) Str::uuid(),
            'created_by' => $this->actor()->id,
            'status' => PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE,
            'customer_name' => 'مشتری آزمایشی',
            'customer_mobile' => '09120000000',
            'customer_address' => 'تهران',
            'province_id' => 1,
            'shipping_id' => 0,
            'shipping_price' => 0,
            'discount_amount' => 0,
            'total_price' => $total,
        ]);
        $preinvoice->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->saveQuietly();

        $invoice = Invoice::query()->create(array_merge([
            'uuid' => str_pad((string) random_int(1, 99999), 5, '0', STR_PAD_LEFT),
            'preinvoice_order_id' => $preinvoice->id,
            'seller_id' => null,
            'customer_name' => 'مشتری آزمایشی',
            'subtotal' => $total,
            'shipping_price' => 0,
            'discount_amount' => 0,
            'total' => $total,
            'status' => Invoice::STATUS_SHIPPED,
            'document_date' => $documentDate,
        ], $overrides));
        $invoice->forceFill(['created_at' => $createdAt, 'updated_at' => $createdAt])->saveQuietly();

        return $invoice->fresh();
    }

    private function item(Invoice $invoice, Product $product, ?ProductVariant $variant, int $quantity, int $price, int $discount = 0): InvoiceItem
    {
        return InvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'product_id' => $product->id,
            'variant_id' => $variant?->id,
            'quantity' => $quantity,
            'price' => $price,
            'line_discount_amount' => $discount,
        ]);
    }

    public function test_commission_base_reconciles_to_invoice_total_with_remainder_on_last_item(): void
    {
        $category = $this->category();
        $productA = $this->product($category->id);
        $productB = $this->product($category->id);

        // Total 1000, weights 300 & 700 (proportions don't divide evenly by construction below).
        $invoice = $this->invoice(1000, '2026-07-10', '2026-07-10 10:00:00');
        $itemA = $this->item($invoice, $productA, null, 1, 333);
        $itemB = $this->item($invoice, $productB, null, 1, 667);

        $preview = $this->calculator()->calculate($invoice->fresh(['items']));

        $this->assertSame(1000, collect($preview['items'])->sum('commission_base'));
        $this->assertTrue($preview['reconciled']);

        // Deterministic: running again yields identical output.
        $again = $this->calculator()->calculate($invoice->fresh(['items']));
        $this->assertSame($preview['items'], $again['items']);

        // Last invoice item (by id) absorbs whatever remainder integer division leaves.
        $lastItemId = max($itemA->id, $itemB->id);
        $lastRow = collect($preview['items'])->firstWhere('invoice_item_id', $lastItemId);
        $expectedRemainder = 1000 - intdiv(333 * 1000, 1000);
        $this->assertSame($expectedRemainder, $lastRow['commission_base']);
    }

    public function test_no_valid_items_produces_warning_and_empty_rows(): void
    {
        $invoice = $this->invoice(1000, '2026-07-10', '2026-07-10 10:00:00');

        $preview = $this->calculator()->calculate($invoice->fresh(['items']));

        $this->assertSame([], $preview['items']);
        $this->assertFalse($preview['reconciled']);
        $this->assertSame(['این فاکتور آیتم معتبر برای محاسبه ندارد.'], $preview['warnings']);
    }

    public function test_zero_weight_items_produce_unmatchable_warning(): void
    {
        $category = $this->category();
        $product = $this->product($category->id);
        $invoice = $this->invoice(1000, '2026-07-10', '2026-07-10 10:00:00');
        // Fully discounted line -> weight is zero.
        $this->item($invoice, $product, null, 1, 500, 500);

        $preview = $this->calculator()->calculate($invoice->fresh(['items']));

        $this->assertSame(['مبنای آیتم‌های فاکتور قابل تطبیق نیست.'], $preview['warnings']);
        $this->assertSame([], $preview['items']);
    }

    public function test_rate_priority_variant_beats_product_beats_category(): void
    {
        $service = app(CommissionRateService::class);
        $actor = $this->actor();
        $root = $this->category(null, 'ریشه');
        $product = $this->product($root->id);
        $variant = $this->variant($product->id);

        $service->setRate('category', $root->id, '1', $actor, now()->subMinutes(5));
        $invoice = $this->invoice(1000, now()->toDateTimeString(), now()->toDateTimeString());
        $this->item($invoice, $product, $variant, 1, 1000);
        $preview = $this->calculator()->calculate($invoice->fresh(['items']));
        $this->assertSame('category', $preview['items'][0]['rule_source']);
        $this->assertSame('1.0000', $preview['items'][0]['rate_percent']);

        $service->setRate('product', $product->id, '2', $actor, now()->subMinutes(3));
        $preview = $this->calculator()->calculate($invoice->fresh(['items']));
        $this->assertSame('product', $preview['items'][0]['rule_source']);
        $this->assertSame('2.0000', $preview['items'][0]['rate_percent']);

        $service->setRate('variant', $variant->id, '3', $actor, now()->subMinute());
        $preview = $this->calculator()->calculate($invoice->fresh(['items']));
        $this->assertSame('variant', $preview['items'][0]['rule_source']);
        $this->assertSame('3.0000', $preview['items'][0]['rate_percent']);
    }

    public function test_uses_document_date_as_reference_when_present(): void
    {
        $service = app(CommissionRateService::class);
        $actor = $this->actor();
        $category = $this->category();
        $product = $this->product($category->id);

        // Rate active only in a narrow window that matches document_date but not created_at.
        $service->setRate('category', $category->id, '5', $actor, '2026-01-01 00:00:00');
        $service->setRate('category', $category->id, '9', $actor, '2026-06-01 00:00:00');

        $invoice = $this->invoice(1000, '2026-06-15', '2026-02-01 10:00:00');
        $this->item($invoice, $product, null, 1, 1000);

        $preview = $this->calculator()->calculate($invoice->fresh(['items']));

        $this->assertSame('2026-06-15', $invoice->document_date->toDateString());
        $this->assertSame('9.0000', $preview['items'][0]['rate_percent']);
    }

    public function test_uses_created_at_as_reference_when_document_date_is_null(): void
    {
        $service = app(CommissionRateService::class);
        $actor = $this->actor();
        $category = $this->category();
        $product = $this->product($category->id);

        $service->setRate('category', $category->id, '5', $actor, '2026-01-01 00:00:00');
        $service->setRate('category', $category->id, '9', $actor, '2026-06-01 00:00:00');

        $invoice = $this->invoice(1000, null, '2026-02-01 10:00:00');
        $this->item($invoice, $product, null, 1, 1000);

        $preview = $this->calculator()->calculate($invoice->fresh(['items']));

        $this->assertNull($invoice->document_date);
        $this->assertSame('5.0000', $preview['items'][0]['rate_percent']);
    }

    public function test_missing_rate_yields_null_commission_and_aggregated_missing_totals(): void
    {
        $category = $this->category();
        $product = $this->product($category->id);
        $invoice = $this->invoice(1000, '2026-07-10', '2026-07-10 10:00:00');
        $this->item($invoice, $product, null, 1, 1000);

        $preview = $this->calculator()->calculate($invoice->fresh(['items']));

        $row = $preview['items'][0];
        $this->assertTrue($row['missing_rate']);
        $this->assertNull($row['calculated_commission']);
        $this->assertNotEmpty($row['warning']);
        $this->assertSame(1000, $preview['missing_rate_base_total']);
        $this->assertSame(1, $preview['missing_rate_item_count']);
        $this->assertSame(0, $preview['calculated_commission_total']);
        $this->assertNotEmpty($preview['warnings']);
    }

    public function test_no_default_rate_is_applied_when_rule_missing(): void
    {
        $category = $this->category();
        $product = $this->product($category->id);
        $invoice = $this->invoice(1000, '2026-07-10', '2026-07-10 10:00:00');
        $this->item($invoice, $product, null, 1, 1000);

        $preview = $this->calculator()->calculate($invoice->fresh(['items']));

        $this->assertSame('0.0000', $preview['items'][0]['rate_percent']);
        $this->assertNull($preview['items'][0]['rate_revision_id']);
        $this->assertNull($preview['items'][0]['rule_source']);
    }

    public function test_calculator_only_depends_on_commission_rate_resolver(): void
    {
        $reflection = new \ReflectionClass(SellerCommissionBasePreviewCalculator::class);
        $constructor = $reflection->getConstructor();

        $this->assertNotNull($constructor);
        $parameters = $constructor->getParameters();
        $this->assertCount(1, $parameters);
        $this->assertSame(CommissionRateResolver::class, $parameters[0]->getType()->getName());
    }
}
