<?php

namespace Tests\Unit;

use App\Services\PurchaseCatalogPriceDecision;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class PurchaseCatalogPriceDecisionTest extends TestCase
{
    #[DataProvider('salePriceWriteCases')]
    public function test_stale_catalog_prices_block_any_explicit_sale_price_edit(
        ?int $oldPurchasePrice,
        int $submittedPrice,
        int $currentCatalogPrice,
        ?int $expectedCatalogPrice,
        bool $stale
    ): void {
        $decision = new PurchaseCatalogPriceDecision();

        $this->assertSame(
            $stale,
            $decision->isStaleForExplicitSalePriceEdit(
                $oldPurchasePrice, $submittedPrice, $currentCatalogPrice, $expectedCatalogPrice
            )
        );
    }

    public function test_initial_pricing_of_a_zero_priced_variant_also_requires_separate_approval(): void
    {
        $decision = new PurchaseCatalogPriceDecision();

        $this->assertTrue($decision->requiresSalePriceApproval(null, 0));
    }

    public static function salePriceWriteCases(): array
    {
        return [
            'quantity-only edit must retain historical snapshot' => [5_000_000, 5_000_000, 4_800_000, null, false],
            'historical sale-price change without a version must be rejected' => [5_000_000, 5_100_000, 4_800_000, null, true],
            'approved price change with a current version is fresh' => [5_000_000, 5_100_000, 4_800_000, 4_800_000, false],
            'price change from old form must be rejected' => [5_000_000, 5_100_000, 4_800_000, 5_000_000, true],
            'initial pricing without a version must be rejected' => [null, 4_800_000, 0, null, true],
            'initial pricing from a form that saw zero is fresh' => [null, 4_800_000, 0, 0, false],
            'no sale-price edit when incoming price equals current' => [null, 5_000_000, 5_000_000, null, false],
            'new row changing current priced variant with fresh value' => [null, 5_000_000, 4_800_000, 4_800_000, false],
            'snapshot edit from stale form is rejected' => [5_000_000, 4_800_000, 4_800_000, 5_000_000, true],
        ];
    }
}
