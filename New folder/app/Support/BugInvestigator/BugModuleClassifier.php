<?php

namespace App\Support\BugInvestigator;

class BugModuleClassifier
{
    public function classify(?string $description, ?string $selectedModule = null): string
    {
        if ($selectedModule) return $selectedModule;
        $text = mb_strtolower((string) $description);
        $map = [
            'proforma' => ['پیش فاکتور', 'پیش‌فاکتور', 'پیشفاکتور', 'proforma', 'quotation'],
            'invoice' => ['فاکتور', 'invoice'],
            'warehouse_issue' => ['حواله', 'حواله فروش', 'انبارداری', 'picking', 'issue'],
            'stock' => ['موجودی', 'کالا', 'stock', 'product', 'inventory'],
            'purchase' => ['خرید', 'purchase'],
            'finance' => ['مالی', 'finance', 'تایید مالی', 'تأیید مالی'],
            'warehouse' => ['انبار', 'warehouse', 'تایید انبار', 'تأیید انبار'],
        ];
        foreach ($map as $module => $keywords) {
            foreach ($keywords as $keyword) {
                if (str_contains($text, mb_strtolower($keyword))) return $module;
            }
        }
        return 'unknown';
    }
}
