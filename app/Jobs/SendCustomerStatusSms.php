<?php

namespace App\Jobs;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PreinvoiceOrder;
use App\Services\Sms\SmsManager;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

final class SendCustomerStatusSms implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    // Provider timeouts are ambiguous: do not automatically send the same SMS again.
    public int $tries = 1;
    public int $timeout = 80;

    public function __construct(
        public string $type,
        public int $documentId,
        public int $customerId,
        public string $status,
    ) {}

    public function handle(SmsManager $sms): void
    {
        if (! config('tracking_sms.enabled')
            || ! in_array($this->status, config("tracking_sms.important_statuses.{$this->type}", []), true)) {
            return;
        }

        $document = match ($this->type) {
            'invoice' => Invoice::query()->find($this->documentId),
            'preinvoice' => PreinvoiceOrder::query()->find($this->documentId),
            default => null,
        };

        // An intermediate status may have already changed again before the worker runs.
        if (! $document || (string) $document->status !== $this->status
            || (int) $document->customer_id !== $this->customerId) {
            $this->skip('document_changed');
            return;
        }

        $customer = Customer::query()
            ->whereKey($this->customerId)
            ->where('is_active', true)
            ->first();

        if (! $customer) {
            $this->skip('customer_unavailable');
            return;
        }

        // Use only customer-registered phone numbers; a document phone snapshot
        // must match one of them to avoid informing an unrelated recipient.
        $registered = array_values(array_unique(array_filter([
            self::normalizeMobile((string) $customer->mobile),
            self::normalizeMobile((string) ($customer->primaryPhone?->phone ?? '')),
        ])));

        $snapshot = trim((string) ($document->customer_mobile ?? ''));
        $phone = $snapshot !== '' ? self::normalizeMobile($snapshot) : ($registered[0] ?? null);
        if ($phone === null || ! in_array($phone, $registered, true)) {
            $this->skip('phone_not_registered_or_mismatched');
            return;
        }

        $labels = $document instanceof Invoice
            ? Invoice::statusLabels()
            : PreinvoiceOrder::statusLabels();
        $label = $labels[$this->status] ?? null;
        if ($label === null) {
            $this->skip('unknown_status');
            return;
        }

        $kind = $this->type === 'invoice' ? 'فاکتور' : 'پیش‌فاکتور';
        $documentLabel = $kind . ' ' . (string) ($document->uuid ?: $document->getKey());

        // Status notifications use a dedicated, approved two-argument pattern.
        // Never fall back to a plain SMS or reuse the login OTP pattern.
        $pattern = trim((string) config('tracking_sms.pattern_id', ''));
        $otpPattern = trim((string) config('services.melipayamak.body_id', ''));
        if (! ctype_digit($pattern) || (int) $pattern < 1
            || ($otpPattern !== '' && $pattern === $otpPattern)) {
            throw new RuntimeException('Configure a valid, separately approved order-status SMS pattern ID.');
        }

        $sms->sendPattern($phone, $pattern, [$documentLabel, $label]);

        // Do not log recipient phone numbers.
        Log::info('Customer status SMS submitted to provider.', [
            'document_type' => $this->type,
            'document_id' => $this->documentId,
            'status' => $this->status,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::warning('Customer status SMS failed (not automatically retried).', [
            'document_type' => $this->type,
            'document_id' => $this->documentId,
            'status' => $this->status,
            'error_type' => $exception ? get_class($exception) : 'unknown',
        ]);
    }

    private function skip(string $reason): void
    {
        Log::info('Customer status SMS skipped.', [
            'document_type' => $this->type,
            'document_id' => $this->documentId,
            'reason' => $reason,
        ]);
    }

    private static function normalizeMobile(string $phone): ?string
    {
        $phone = strtr($phone, [
            '۰'=>'0', '۱'=>'1', '۲'=>'2', '۳'=>'3', '۴'=>'4',
            '۵'=>'5', '۶'=>'6', '۷'=>'7', '۸'=>'8', '۹'=>'9',
            '٠'=>'0', '١'=>'1', '٢'=>'2', '٣'=>'3', '٤'=>'4',
            '٥'=>'5', '٦'=>'6', '٧'=>'7', '٨'=>'8', '٩'=>'9',
        ]);
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if (str_starts_with($phone, '0098')) {
            $phone = substr($phone, 4);
        } elseif (str_starts_with($phone, '98')) {
            $phone = substr($phone, 2);
        }
        if (preg_match('/^9[0-9]{9}$/D', $phone)) {
            $phone = '0' . $phone;
        }

        return preg_match('/^09[0-9]{9}$/D', $phone) ? $phone : null;
    }
}
