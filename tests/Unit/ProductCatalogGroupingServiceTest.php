<?php

namespace Tests\Unit;

use App\Models\Color;
use App\Models\ModelList;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\ProductCatalogGroupingService;
use Illuminate\Support\Collection;
use Tests\TestCase;

class ProductCatalogGroupingServiceTest extends TestCase
{
    private ProductCatalogGroupingService $service;

    protected function setUp(): void { parent::setUp(); $this->service = new ProductCatalogGroupingService(); }

    public function test_same_price_and_same_colors_merge_models(): void
    {
        $result = $this->service->group($this->product(), new Collection([
            $this->variant('A05', 'آبی'), $this->variant('A05', 'سبز'), $this->variant('A05', 'مشکی'),
            $this->variant('A06', 'آبی'), $this->variant('A06', 'سبز'), $this->variant('A06', 'مشکی'),
        ]));
        $this->assertCount(1, $result['groups']);
        $this->assertSame(['A05', 'A06'], $result['groups'][0]['models']);
        $this->assertSame(['آبی', 'سبز', 'مشکی'], array_column($result['groups'][0]['colors'], 'name'));
    }

    public function test_different_color_sets_remain_separate(): void
    {
        $result = $this->service->group($this->product(), new Collection([
            $this->variant('A05', 'آبی'), $this->variant('A05', 'سبز'), $this->variant('A05', 'مشکی'), $this->variant('A06', 'مشکی'),
        ]));
        $this->assertCount(2, $result['groups']);
        $this->assertEqualsCanonicalizing(['A05', 'A06'], collect($result['groups'])->flatMap(fn ($g) => $g['models'])->all());
    }

    public function test_different_prices_remain_separate_and_summary_uses_range(): void
    {
        $result = $this->service->group($this->product(), new Collection([$this->variant('A05', 'مشکی', 1280000), $this->variant('A06', 'مشکی', 1490000)]));
        $this->assertCount(2, $result['groups']);
        $this->assertSame('از 1,280,000 ریال تا 1,490,000 ریال', $result['price_summary']);
    }

    public function test_deduplicates_identical_variants(): void
    {
        $result = $this->service->group($this->product(), new Collection([$this->variant('A15', 'مشکی'), $this->variant('A15', 'مشکی')]));
        $this->assertCount(1, $result['groups']);
        $this->assertSame(['A15'], $result['groups'][0]['models']);
        $this->assertSame(['مشکی'], array_column($result['groups'][0]['colors'], 'name'));
    }

    public function test_models_are_naturally_sorted(): void
    {
        $result = $this->service->group($this->product(), new Collection([$this->variant('A100', 'مشکی'), $this->variant('A5', 'مشکی'), $this->variant('A20', 'مشکی'), $this->variant('A10', 'مشکی')]));
        $this->assertSame(['A5', 'A10', 'A20', 'A100'], $result['groups'][0]['models']);
    }

    public function test_without_price_has_single_unpriced_label(): void
    {
        $result = $this->service->group($this->product(null), new Collection([$this->variant('A15', 'مشکی', null), $this->variant('A15', 'آبی', null)]));
        $this->assertFalse($result['has_price']);
        $this->assertSame('بدون قیمت', $result['price_summary']);
        $this->assertSame('قیمت ثبت نشده', $result['groups'][0]['price_label']);
    }

    private function product(?int $price = 1000): Product { $p = new Product(['name' => 'گارد', 'price' => $price]); return $p; }
    private function variant(string $model, string $color, ?int $price = 1890000): ProductVariant
    {
        $v = new ProductVariant(['variant_name' => $model, 'variety_name' => $color, 'sell_price' => $price, 'is_active' => true]);
        $v->setRelation('modelList', new ModelList(['model_name' => $model]));
        $v->setRelation('color', new Color(['name' => $color]));
        return $v;
    }
}
