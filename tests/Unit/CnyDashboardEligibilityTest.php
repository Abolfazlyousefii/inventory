<?php

use App\Models\{CnyExchangeRate,ProductCnyPricing};
use App\Services\{CnyPriceCalculator,CnyPricingService};

it('shares exact repricing eligibility across the dashboard and scheduled pricing service',function(){
    $service = app(CnyPricingService::class);
    $old = new CnyExchangeRate(['rate_rial'=>740000,'quoted_at'=>now()->subHour()]);
    $old->id = 10;
    $new = new CnyExchangeRate(['rate_rial'=>760000,'quoted_at'=>now()]);
    $new->id = 11;
    $policy = new ProductCnyPricing(['enabled'=>true,'buy_cny_units'=>10000,'applied_rate_id'=>10,'policy'=>CnyPriceCalculator::POLICY]);
    expect($service->eligibleForReprice($policy,$new,$old))->toBeTrue();
    $policy->enabled=false;
    expect($service->eligibleForReprice($policy,$new,$old))->toBeFalse();
    $policy->enabled=true;
    $policy->applied_rate_id=11;
    expect($service->eligibleForReprice($policy,$new,$old))->toBeFalse();
    $policy->applied_rate_id=10;
    $new->rate_rial=745000;
    expect($service->eligibleForReprice($policy,$new,$old))->toBeFalse();
    $new->rate_rial=760000;
    $new->quoted_at=now()->subHours(2);
    expect($service->eligibleForReprice($policy,$new,$old))->toBeFalse();
    expect($service->eligibleForReprice($policy,$new,null))->toBeFalse();
});
