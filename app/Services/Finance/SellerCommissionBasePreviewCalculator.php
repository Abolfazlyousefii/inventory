<?php

namespace App\Services\Finance;

use App\Models\Invoice;
use App\Services\Commissions\CommissionMoney;
use App\Services\Commissions\CommissionRateResolver;

class SellerCommissionBasePreviewCalculator
{
    public function __construct(private readonly CommissionRateResolver $rates) {}

    public function warm($start, $end): void { $this->rates->warm($start, $end); }

    public function calculate(Invoice $invoice): array
    {
        $items = $invoice->items->sortBy('id')->values();
        if ($items->isEmpty()) return $this->invalid($invoice, 'این فاکتور آیتم معتبر برای محاسبه ندارد.');
        $weights = $items->map(fn ($item) => max((int) $item->quantity * (int) $item->price - (int) ($item->line_discount_amount ?? 0), 0));
        $weightTotal = $weights->sum();
        if ($weightTotal <= 0) return $this->invalid($invoice, 'مبنای آیتم‌های فاکتور قابل تطبیق نیست.');
        $total = (int) $invoice->total; $remaining = $total; $referenceDate = $invoice->display_document_date; $rows = [];
        foreach ($items as $index => $item) {
            $base = $index === $items->count() - 1 ? $remaining : intdiv($weights[$index] * $total, $weightTotal);
            $remaining -= $base;
            $rate = $this->rates->resolve($item->product, $item->variant, $referenceDate);
            $rows[] = ['invoice_item_id'=>(int)$item->id,'product_id'=>(int)$item->product_id,'product_name'=>$item->product?->name,'variant_id'=>$item->variant_id,'variant_name'=>$item->variant?->variant_name,'quantity'=>(int)$item->quantity,'commission_base'=>$base,'rate_percent'=>$rate->percentage,'rule_source'=>$rate->sourceType,'rate_revision_id'=>$rate->ruleId,'calculated_commission'=>$rate->isMissing?null:CommissionMoney::percentageOf($base,$rate->percentage),'missing_rate'=>$rate->isMissing,'warning'=>$rate->isMissing?'برای این کالا نرخ پورسانت تعریف نشده است.':null];
        }
        $missing = collect($rows)->where('missing_rate', true);
        return ['invoice_id'=>$invoice->id,'invoice_number'=>$invoice->uuid,'reference_date'=>$referenceDate,'invoice_total'=>(int)$invoice->total,'item_base_total'=>collect($rows)->sum('commission_base'),'reconciled'=>collect($rows)->sum('commission_base') === (int)$invoice->total,'calculated_commission_total'=>collect($rows)->where('missing_rate',false)->sum('calculated_commission'),'missing_rate_item_count'=>$missing->count(),'missing_rate_base_total'=>$missing->sum('commission_base'),'warnings'=>$missing->isNotEmpty()?['برای بخشی از کالاهای این گزارش نرخ پورسانت تعریف نشده است؛ پیش از صدور سند، نرخ‌ها را بررسی کنید.']:[],'items'=>$rows];
    }
    private function invalid(Invoice $invoice,string $warning): array { return ['invoice_id'=>$invoice->id,'invoice_number'=>$invoice->uuid,'reference_date'=>$invoice->display_document_date,'invoice_total'=>(int)$invoice->total,'item_base_total'=>0,'reconciled'=>false,'calculated_commission_total'=>0,'missing_rate_item_count'=>0,'missing_rate_base_total'=>0,'warnings'=>[$warning],'items'=>[]]; }
}
