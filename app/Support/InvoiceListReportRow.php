<?php

namespace App\Support;

use App\Models\Invoice;

final class InvoiceListReportRow
{
    public static function values(Invoice $invoice, array $statusLabels, int $number): array
    {
        $total=(int)$invoice->total;
        $paid=(int)($invoice->paid_total ?? 0);
        return [$number, (string)$invoice->uuid, JalaliDate::dateTime($invoice->created_at),
            $invoice->customer_name ?: ($invoice->customer?->display_name ?: '—'),
            $invoice->effectiveSeller()?->name ?? '—',
            $statusLabels[$invoice->status] ?? ($invoice->status ?: '—'),
            $total, $paid, max(0,$total-$paid)];
    }
}
