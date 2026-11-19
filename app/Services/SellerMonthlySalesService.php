<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\User;
use App\Support\Currency;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Schema;
use Morilog\Jalali\Jalalian;

class SellerMonthlySalesService
{
    public function summary(User $seller): array
    {
        $timezone = config('app.timezone', 'Asia/Tehran');
        $today = CarbonImmutable::instance(now($timezone))->startOfDay();
        $jalali = Jalalian::fromCarbon($today->toMutable());
        $year = $jalali->getYear();
        $month = $jalali->getMonth();

        $start = $this->jalaliMonthStart($year, $month, $timezone);
        $end = $this->jalaliMonthStart($month === 12 ? $year + 1 : $year, $month === 12 ? 1 : $month + 1, $timezone);
        $remaining = max(0, (int) $today->diff($end)->days - 1);
        $monthNames = [1 => 'فروردین', 'اردیبهشت', 'خرداد', 'تیر', 'مرداد', 'شهریور', 'مهر', 'آبان', 'آذر', 'دی', 'بهمن', 'اسفند'];
        $yearLabel = strtr((string) $year, [
            '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
            '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
        ]);

        // Older databases may not have the preinvoice ownership column yet.
        $salesRial = 0;
        if (Schema::hasColumn('preinvoice_orders', 'seller_id') && Schema::hasColumn('invoices', 'seller_id')) {
            $sellerSql = Invoice::effectiveSellerSql('invoices', 'monthly_preinvoices');
            $salesRial = (int) Invoice::query()
                ->leftJoin('preinvoice_orders as monthly_preinvoices', 'monthly_preinvoices.id', '=', 'invoices.preinvoice_order_id')
                ->whereRaw("{$sellerSql} = ?", [(int) $seller->id])
                ->where(function ($query): void {
                    $query->whereNull('invoices.status')
                        ->orWhereNotIn('invoices.status', Invoice::cancelledStatuses());
                })
                ->whereRaw('COALESCE(invoices.document_date, invoices.created_at) >= ?', [$start->toDateTimeString()])
                ->whereRaw('COALESCE(invoices.document_date, invoices.created_at) < ?', [$end->toDateTimeString()])
                ->sum('invoices.total');
        }

        return [
            'month_label' => $monthNames[$month].' '.$yearLabel,
            'days_remaining' => $remaining,
            'sales_toman' => Currency::rialToToman($salesRial),
        ];
    }

    private function jalaliMonthStart(int $year, int $month, string $timezone): CarbonImmutable
    {
        $gregorian = (new Jalalian($year, $month, 1))->toCarbon();

        return CarbonImmutable::parse($gregorian->format('Y-m-d').' 00:00:00', $timezone);
    }
}
