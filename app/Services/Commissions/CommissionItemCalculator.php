<?php

namespace App\Services\Commissions;

use App\Data\CommissionItemCalculation;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Services\SalesReturnCalculationService;
use Carbon\CarbonInterface;

class CommissionItemCalculator
{
    public const CALCULATION_VERSION = 1;

    public function __construct(
        private readonly CommissionRateResolver $rates,
        private readonly CommissionCampaignResolver $campaigns,
        private readonly SalesReturnCalculationService $financials,
    ) {}

    private array $invoiceBreakdowns = [];

    public function warm(CarbonInterface|string $start, CarbonInterface|string $end): void
    {
        $this->invoiceBreakdowns = [];
        $this->rates->warm($start, $end);
        $this->campaigns->warm($start, $end);
    }

    public function calculate(Invoice $invoice, InvoiceItem $item, int $sellerId, CarbonInterface $invoiceDate): CommissionItemCalculation
    {
        $breakdown = ($this->invoiceBreakdowns[$invoice->id] ??= $this->financials->invoiceItemBreakdowns($invoice))[$item->id];
        $product = $item->product;
        $variant = $item->variant;
        $rate = $this->rates->resolve($product, $variant, $invoiceDate);
        $campaign = $this->campaigns->resolve($product, $variant, $invoiceDate);
        $baseRate = $rate->percentage;
        $campaignRate = $campaign?->bonusPercentage ?? '0.0000';
        $effectiveRate = CommissionMoney::addPercentages($baseRate, $campaignRate);
        $net = (int) $breakdown['net_refund_total'];
        $baseCommission = CommissionMoney::percentageOf($net, $baseRate);
        $campaignCommission = CommissionMoney::percentageOf($net, $campaignRate);
        $attributes = [
            'seller_id' => $sellerId, 'invoice_id' => $invoice->id, 'invoice_item_id' => $item->id,
            'product_id' => $item->product_id, 'product_variant_id' => $item->variant_id,
            'invoice_number_snapshot' => (string) $invoice->uuid, 'invoice_date_snapshot' => $invoiceDate,
            'product_name_snapshot' => (string) $product->name, 'variant_name_snapshot' => $variant?->variant_name,
            'quantity_snapshot' => (int) $item->quantity,
            'gross_amount_snapshot' => (int) $breakdown['gross_amount'],
            'discount_amount_snapshot' => (int) $breakdown['line_discount_total'] + (int) $breakdown['allocated_invoice_discount_total'],
            'net_amount_snapshot' => $net,
            'base_rate_snapshot' => $baseRate, 'campaign_rate_snapshot' => $campaignRate, 'effective_rate_snapshot' => $effectiveRate,
            'base_commission_amount' => $baseCommission, 'campaign_commission_amount' => $campaignCommission,
            'total_commission_amount' => $baseCommission + $campaignCommission,
            'rate_rule_id' => $rate->ruleId, 'rate_source_type' => $rate->sourceType, 'rate_source_id' => $rate->sourceId,
            'campaign_id' => $campaign?->campaignId, 'campaign_name_snapshot' => $campaign?->campaignName,
            'missing_rate' => $rate->isMissing, 'calculation_version' => self::CALCULATION_VERSION,
        ];

        return new CommissionItemCalculation($attributes, [
            'seller_id' => $sellerId, 'invoice_date' => $invoiceDate->toDateTimeString(), 'net_amount' => $net,
            'rate_source' => [$rate->sourceType, $rate->sourceId], 'base_rate' => $baseRate,
            'campaign' => $campaign?->campaignId, 'result' => $baseCommission + $campaignCommission,
        ]);
    }
}
