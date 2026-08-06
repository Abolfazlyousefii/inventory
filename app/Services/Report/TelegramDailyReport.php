<?php

namespace App\Services\Report;

use App\Models\Cheque;
use App\Models\Invoice;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Morilog\Jalali\Jalalian;
use RuntimeException;

class TelegramDailyReport {
    private const WEBHOOK_ROUTE = 'sendMessage';

    /**
     * Generate and send today's report.
     */
    public function send(): void {
        $reportDay = $this->resolveReportDay();
        $metrics   = $this->collectMetrics($reportDay);
        $message   = $this->buildMessage($reportDay, $metrics);
        $response  = $this->sendToBotWebhook($message);

        Log::info('Telegram daily report sent.', [
            'report_date' => $reportDay->toDateString(),

            'chat_id' => (string) config('services.telegram_daily_report.chat_id'),

            'webhook_status' => $response->status(),

            'telegram_message_id' => $this->extractMessageId($response),
        ]);
    }

    /**
     * Return today's date using the application timezone.
     */
    private function resolveReportDay(): CarbonImmutable {
        $timezone = (string) config('app.timezone', 'Asia/Tehran');

        return CarbonImmutable::now($timezone)
            ->startOfDay();
    }

    /**
     * Collect all report metrics for the selected day.
     */
    private function collectMetrics( CarbonImmutable $reportDay ): array {
        $start = $reportDay->startOfDay();
        $end   = $reportDay->endOfDay();

        $previousStart = $reportDay->subDay()
            ->startOfDay();

        $previousEnd = $reportDay->subDay()
            ->endOfDay();

        $submittedInvoices = $this->submittedInvoicesBetween($start, $end);

        $aggregate = ( clone $submittedInvoices )->selectRaw('COUNT(*) AS invoice_count, ' . 'COALESCE(SUM(total), 0) AS total_amount, ' . 'COALESCE(AVG(total), 0) AS average_amount')
            ->first();

        $largestInvoice = ( clone $submittedInvoices )->orderByDesc('total')
            ->orderBy('id')
            ->first([
                'id',
                'uuid',
                'customer_name',
                'total',
            ]);

        $previousTotal = (int) $this->submittedInvoicesBetween($previousStart, $previousEnd)
            ->sum('total');

        $shippedCount = Invoice::query()
            ->where('status', Invoice::STATUS_SHIPPED)
            ->whereBetween('shipped_at', [ $start, $end ])
            ->count();

        $cancelledCount = Invoice::query()
            ->where('status', Invoice::STATUS_NOT_SHIPPED)
            ->whereBetween('cancelled_at', [ $start, $end ])
            ->count();

        $chequeMetrics = $this->collectChequeMetrics($reportDay);

        return [
            'invoice_count' => (int) ( $aggregate?->invoice_count ?? 0 ),

            'total_amount' => (int) ( $aggregate?->total_amount ?? 0 ),

            'average_amount' => (int) round((float) ( $aggregate?->average_amount ?? 0 )),

            'largest_invoice' => $largestInvoice,

            'previous_total' => $previousTotal,

            'shipped_count' => $shippedCount,

            'cancelled_count' => $cancelledCount,

            ...$chequeMetrics,
        ];
    }

    /**
     * Query submitted, non-cancelled invoices between two dates.
     *
     * document_date is used first. If document_date is empty,
     * created_at is used as the fallback.
     */
    private function submittedInvoicesBetween( CarbonImmutable $start, CarbonImmutable $end ): Builder {
        return Invoice::query()
            ->active()
            ->where(function ( Builder $query ) use (
                $start, $end
            ): void {
                $query->where(function ( Builder $documentDateQuery ) use ( $start, $end ): void {
                        $documentDateQuery->whereDate('document_date', '>=', $start->toDateString())
                            ->whereDate('document_date', '<=', $end->toDateString());
                    })
                    ->orWhere(function ( Builder $fallbackQuery ) use ( $start, $end ): void {
                        $fallbackQuery->whereNull('document_date')
                            ->whereBetween('created_at', [ $start, $end ]);
                    });
            });
    }

    /**
     * Collect upcoming and overdue cheque information.
     */
    private function collectChequeMetrics( CarbonImmutable $reportDay ): array {
        $dueDays = max(1, (int) config('services.telegram_daily_report.cheque_due_days', 7));

        /*
         * Upcoming cheque interval begins tomorrow.
         *
         * For example, when cheque_due_days is 7,
         * exactly seven calendar days are included.
         */
        $dueStart = $reportDay->addDay()
            ->startOfDay();

        $dueEnd = $dueStart->addDays($dueDays - 1)
            ->endOfDay();

        $dueCheques = Cheque::query()
            ->where('status', 'pending')
            ->whereBetween('due_date', [
                    $dueStart->toDateString(),
                    $dueEnd->toDateString(),
                ]);

        /*
         * Because the report runs near the end of today,
         * pending cheques due today or earlier are overdue.
         */
        $overdueCheques = Cheque::query()
            ->where('status', 'pending')
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<', $dueStart->toDateString());

        return [
            'due_cheque_count' => ( clone $dueCheques )->count(),

            'due_cheque_amount' => (int) ( clone $dueCheques )->sum('amount'),

            'overdue_cheque_count' => ( clone $overdueCheques )->count(),

            'overdue_cheque_amount' => (int) ( clone $overdueCheques )->sum('amount'),

            'cheque_due_days' => $dueDays,
        ];
    }

    /**
     * Build the Persian Telegram report message.
     */
    private function buildMessage( CarbonImmutable $reportDay, array $metrics ): string {
        $jalaliDate = Jalalian::fromCarbon(
            $reportDay->toMutable()
        )->format('Y/m/d');

        $comparison = $this->comparisonText((int) $metrics['total_amount'], (int) $metrics['previous_total']);
        $largestInvoiceText = $this->largestInvoiceText($metrics['largest_invoice']);

        return implode("\n", [
            '📊 <b>گزارش روزانه فروش و ارسال</b>',

            '🗓 <b>تاریخ گزارش:</b> ' . $this->escape($jalaliDate) . ' (' . $reportDay->format('Y-m-d') . ')',

            '',

            '<b>💰 فروش</b>',

            '• فاکتورهای ثبت‌شده و غیرلغو: <b>' . number_format((int) $metrics['invoice_count']) . '</b>',

            '• مبلغ کل فاکتورها: <b>' . $this->money((int) $metrics['total_amount']) . '</b>',

            '• میانگین مبلغ هر فاکتور: <b>' . $this->money((int) $metrics['average_amount']) . '</b>',

            '• بیشترین فاکتور: <b>' . $largestInvoiceText . '</b>',

            '• مقایسه فروش با روز قبل: <b>' . $comparison . '</b>',

            '• مبلغ فروش روز قبل: <b>' . $this->money((int) $metrics['previous_total']) . '</b>',

            '',

            '<b>📦 ارسال و لغو</b>',

            '• فاکتورهای ارسال‌شده امروز: <b>' . number_format((int) $metrics['shipped_count']) . '</b>',

            '• فاکتورهای لغوشده امروز: <b>' . number_format((int) $metrics['cancelled_count']) . '</b>',

            '',

            '<b>⚠️ هشدار چک‌ها</b>',

            '• نزدیک سررسید تا ' . number_format((int) $metrics['cheque_due_days']) . ' روز آینده: <b>' . number_format((int) $metrics['due_cheque_count']) . ' فقره</b> | ' . $this->money((int) $metrics['due_cheque_amount']),

            '• چک‌های معوق: <b>' . number_format((int) $metrics['overdue_cheque_count']) . ' فقره</b> | ' . $this->money((int) $metrics['overdue_cheque_amount']),

            '',

            '<i>' . 'مبنای فروش: تاریخ سند فاکتور و در نبود آن ' . 'تاریخ ثبت؛ فقط فاکتورهای غیرلغو. ' . 'مبالغ بر اساس invoices.total و به ریال هستند. ' . 'مرجوعی فروش در این نسخه کسر نمی‌شود.' . '</i>',
        ]);
    }

    /**
     * Format the largest submitted invoice.
     */
    private function largestInvoiceText( ?Invoice $largestInvoice ): string {
        if ( $largestInvoice === null ) {
            return '—';
        }

        $customerName = trim((string) $largestInvoice->customer_name);

        if ( $customerName === '' ) {
            $customerName = 'بدون نام';
        }

        return $this->money((int) $largestInvoice->total) . ' | شماره <code>' . $this->escape((string) $largestInvoice->uuid) . '</code>' . ' | ' . $this->escape($customerName);
    }

    /**
     * Compare today's sales total with yesterday.
     */
    private function comparisonText( int $currentTotal, int $previousTotal ): string {
        if ( $previousTotal === 0 ) {
            if ( $currentTotal === 0 ) {
                return '➖ بدون تغییر (۰٪)';
            }

            return '🆕 درصد قابل محاسبه نیست؛ ' . 'فروش روز قبل صفر بوده است';
        }

        $percentage = round(( ( $currentTotal - $previousTotal ) / $previousTotal ) * 100, 1);

        if ( $percentage > 0 ) {
            return '🔺 ' . number_format(abs($percentage), 1) . '٪ افزایش';
        }

        if ( $percentage < 0 ) {
            return '🔻 ' . number_format(abs($percentage), 1) . '٪ کاهش';
        }

        return '➖ بدون تغییر (۰٪)';
    }

    /**
     * Send the report to the Natilosir SDK webhook.
     */
    private function sendToBotWebhook( string $message ): Response {
dd($message);
        $response = Http::acceptJson()
            ->asJson()
            ->connectTimeout(5)
            ->timeout(20)
            ->retry(times : 2, sleepMilliseconds : 500, throw : false)
            ->post($webhookUrl, [
                    'route' => self::WEBHOOK_ROUTE,

                    'data' => [
                        'chat_id' => $chatId,
                        'text'    => $message,
                    ],
                ]);
    }

    /**
     * Get the Telegram message ID from either webhook response format.
     */
    private function extractMessageId( Response $response ): int|string|null {
        $messageId = $response->json('data.message_id');

        if ( $messageId === null ) {
            $messageId = $response->json('message_id');
        }

        if ( !is_int($messageId)
             && !is_string($messageId) ) {
            return null;
        }

        return $messageId;
    }

    /**
     * Format a monetary amount.
     */
    private function money( int $amount ): string {
        return number_format($amount) . ' ریال';
    }

    /**
     * Escape dynamic text for Telegram HTML mode.
     */
    private function escape( string $value ): string {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}