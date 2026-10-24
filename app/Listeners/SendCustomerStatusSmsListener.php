<?php

namespace App\Listeners;

use App\Events\CustomerOrderStatusChanged;
use App\Facades\Sms;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PreinvoiceOrder;
use Illuminate\Support\Facades\Log;
use RuntimeException;
use Throwable;

class SendCustomerStatusSmsListener {
    public function handle( CustomerOrderStatusChanged $event ): void {
        try {
            $this->send($event);
        } catch ( Throwable $exception ) {
            // Never roll back a committed status change because the SMS provider failed.
            // Do not expose recipient numbers, provider tokens or message contents in logs.
            Log::warning('Customer status SMS failed', [
                'document_type' => $event->type,
                'document_id'   => $event->documentId,
                'status'        => $event->status,
                'error_type'    => $exception->getMessage(),
            ]);
        }
    }

    public function send( CustomerOrderStatusChanged $event ): void {
        $document = match ( $event->type ) {
            'invoice'    => Invoice::find($event->documentId),
            'preinvoice' => PreinvoiceOrder::find($event->documentId),
            default      => null,
        };

        // The status may change again before the after-commit event is handled.
        if ( !$document || (string) $document->status !== $event->status
             || (int) $document->customer_id !== $event->customerId ) {
            $this->skip($event, 'document_changed');
            return;
        }

        // Cancelling an invoice also cancels its linked preinvoice. The invoice
        // owns that cancellation notification; do not send another for its mirror.
        if ( $document instanceof PreinvoiceOrder
             && in_array($event->status, [
                PreinvoiceOrder::STATUS_CANCELLED_BY_WAREHOUSE,
                PreinvoiceOrder::STATUS_CANCELLED_BY_FINANCE,
            ], true)
             && Invoice::where('preinvoice_order_id', $document->getKey())
                 ->where('customer_id', $event->customerId)
                 ->where('status', Invoice::STATUS_NOT_SHIPPED)
                 ->exists() ) {
            $this->skip($event, 'linked_invoice_cancellation');
            return;
        }

        $customer = Customer::whereKey($event->customerId)
            ->where('is_active', true)
            ->first();

        if ( !$customer ) {
            $this->skip($event, 'customer_unavailable');
            return;
        }

        // Use only customer-registered phone numbers; a document phone snapshot
        // must match one of them to avoid informing an unrelated recipient.
        $registered = array_values(array_unique(array_filter([
            self::normalizeMobile((string) $customer->mobile),
            self::normalizeMobile((string) ( $customer->primaryPhone?->phone ?? '' )),
        ])));

        $snapshot = trim((string) ( $document->customer_mobile ?? '' ));
        $phone    = $snapshot !== '' ? self::normalizeMobile($snapshot) : ( $registered[0] ?? null );
        if ( $phone === null || !in_array($phone, $registered, true) ) {
            $this->skip($event, 'phone_not_registered_or_mismatched');
            return;
        }

        $label = match ( true ) {
            $document instanceof Invoice && $event->status === Invoice::STATUS_COLLECTING => 'در حال جمع آوری',
            $document instanceof Invoice && $event->status === Invoice::STATUS_SHIPPED    => 'ارسال شده',
            ( $document instanceof Invoice && $event->status === Invoice::STATUS_NOT_SHIPPED )
            || ( $document instanceof PreinvoiceOrder
                 && in_array($event->status, [
                    PreinvoiceOrder::STATUS_CANCELLED_BY_WAREHOUSE,
                    PreinvoiceOrder::STATUS_CANCELLED_BY_FINANCE,
                ], true) )                                                                => 'لغو شده',
            default                                                                       => null,
        };
        if ( $label === null ) {
            $this->skip($event, 'unknown_status');
            return;
        }



        $kind          = $event->type === 'invoice' ? 'فاکتور' : 'پیش‌فاکتور';
        $documentLabel = $kind . ' ' . (string) ( $document->uuid ? : $document->getKey() );

        // Status notifications use a dedicated, approved two-argument pattern.
        // Never fall back to a plain SMS or reuse the login OTP pattern.
        $pattern    = trim((string) config('tracking_sms.pattern_id', ''));

        $sms = SMS::sendPattern($phone, $pattern, [ $documentLabel, $label ]);

        // Do not log recipient phone numbers.
        Log::info('Customer status SMS submitted to provider.', [
            'document_type' => $event->type,
            'document_id'   => $event->documentId,
            'status'        => $event->status,
            'sms'           => $sms,
        ]);
    }

    private function skip( CustomerOrderStatusChanged $event, string $reason ): void {
        Log::info('Customer status SMS skipped.', [
            'document_type' => $event->type,
            'document_id'   => $event->documentId,
            'reason'        => $reason,
        ]);
    }

    private static function normalizeMobile( string $phone ): ?string {
        $phone = strtr($phone, [
            '۰' => '0',
            '۱' => '1',
            '۲' => '2',
            '۳' => '3',
            '۴' => '4',
            '۵' => '5',
            '۶' => '6',
            '۷' => '7',
            '۸' => '8',
            '۹' => '9',
            '٠' => '0',
            '١' => '1',
            '٢' => '2',
            '٣' => '3',
            '٤' => '4',
            '٥' => '5',
            '٦' => '6',
            '٧' => '7',
            '٨' => '8',
            '٩' => '9',
        ]);
        $phone = preg_replace('/[^0-9]/', '', $phone);
        if ( str_starts_with($phone, '0098') ) {
            $phone = substr($phone, 4);
        }
        elseif ( str_starts_with($phone, '98') ) {
            $phone = substr($phone, 2);
        }
        if ( preg_match('/^9[0-9]{9}$/D', $phone) ) {
            $phone = '0' . $phone;
        }

        return preg_match('/^09[0-9]{9}$/D', $phone) ? $phone : null;
    }
}
