<?php

use App\Services\PurchaseCatalogPriceDecision;

it('does not replay the historical price when quantity or notes on purchase 578 change', function (): void {
    $policy = new PurchaseCatalogPriceDecision();
    // Purchased price 5m; live catalog was reduced to 4.8m.
    expect($policy->isExplicitChange(5_000_000, 5_000_000, 4_800_000))->toBeFalse()
        ->and($policy->isExplicitChange(2_000_000, 2_000_000, 2_100_000))->toBeFalse();
});

it('requires approval for a real edit of a historical purchase sale price', function (): void {
    $policy = new PurchaseCatalogPriceDecision();
    expect($policy->isExplicitChange(5_000_000, 5_300_000, 4_800_000))->toBeTrue()
        ->and($policy->requiresSalePriceApproval(5_000_000, 4_800_000))->toBeTrue();
});

it('requires approval for a newly purchased variant with an existing live sale price', function (): void {
    $policy = new PurchaseCatalogPriceDecision();
    expect($policy->isExplicitChange(null, 5_000_000, 4_800_000))->toBeTrue()
        ->and($policy->requiresSalePriceApproval(null, 4_800_000))->toBeTrue();
});

it('preserves existing sale price when a new purchase matches it', function (): void {
    $policy = new PurchaseCatalogPriceDecision();
    expect($policy->isExplicitChange(null, 4_800_000, 4_800_000))->toBeFalse();
});

it('allows only initial pricing of a previously unpriced variant without additional approval', function (): void {
    $policy = new PurchaseCatalogPriceDecision();
    expect($policy->isExplicitChange(null, 4_800_000, 0))->toBeTrue()
        ->and($policy->requiresSalePriceApproval(null, 0))->toBeFalse();
});

it('rejects a changed catalog price that the editor has not freshly acknowledged', function (): void {
    $policy = new PurchaseCatalogPriceDecision();
    expect($policy->isCurrentPriceStale(5_000_000, 4_800_000))->toBeTrue()
        ->and($policy->isCurrentPriceStale(null, 4_800_000))->toBeTrue()
        ->and($policy->isCurrentPriceStale(4_800_000, 4_800_000))->toBeFalse();
});

it('recognizes a deliberate correction of a historical price to exactly the current price', function (): void {
    $policy = new PurchaseCatalogPriceDecision();
    expect($policy->isExplicitChange(5_000_000, 4_800_000, 4_800_000))->toBeTrue()
        ->and($policy->requiresSalePriceApproval(5_000_000, 4_800_000))->toBeTrue();
});
