<?php

namespace Tests\Unit;

use App\Support\StockCountQuantityCalculator;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class StockCountQuantityCalculatorTest extends TestCase
{
    public function test_expected_physical_includes_active_reservations_bundle_components_and_site_holds(): void
    {
        $this->assertSame(
            15,
            StockCountQuantityCalculator::expectedPhysical(
                available: 5,
                reserved: 2,
                bundleFree: 3,
                bundleReserved: 1,
                siteHeld: 4,
            )
        );
    }

    public function test_available_after_count_excludes_all_protected_quantities(): void
    {
        $this->assertSame(
            5,
            StockCountQuantityCalculator::availableAfterCount(
                actual: 15,
                reserved: 2,
                bundleFree: 3,
                bundleReserved: 1,
                siteHeld: 4,
            )
        );
    }

    public function test_count_below_protected_quantities_is_negative_and_must_be_rejected_by_finalize(): void
    {
        $this->assertSame(
            -1,
            StockCountQuantityCalculator::availableAfterCount(
                actual: 9,
                reserved: 2,
                bundleFree: 3,
                bundleReserved: 1,
                siteHeld: 4,
            )
        );
    }

    public function test_negative_protected_quantities_are_rejected(): void
    {
        $this->expectException(InvalidArgumentException::class);

        StockCountQuantityCalculator::expectedPhysical(
            available: 5,
            reserved: -1,
            bundleFree: 0,
            bundleReserved: 0,
            siteHeld: 0,
        );
    }
}
