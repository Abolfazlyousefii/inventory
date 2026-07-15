<?php

namespace App\Services;

use App\Models\Cheque;
use App\Models\CustomerLedger;
use App\Models\Invoice;
use App\Models\InvoicePayment;

class PaymentRegistrationService
{
    public function registerForInvoice(
        Invoice $invoice,
        array $payload,
        ?int $customerId = null,
        ?int $createdBy = null,
        ?string $receiptImagePath = null,
        ?string $chequeImagePath = null
    ): InvoicePayment {
        $method = (string) ($payload['method'] ?? 'cash');
        $amount = (int) ($payload['amount'] ?? 0);
        $paidAt = $method === 'cheque'
            ? ($payload['received_at'] ?? $payload['cheque_received_at'] ?? $payload['paid_at'] ?? now()->toDateString())
            : ($payload['paid_at'] ?? now()->toDateString());

        $payment = $invoice->payments()->create([
            'customer_id' => $customerId ?: ($invoice->customer_id ?: null),
            'created_by' => $createdBy,
            'method' => $method,
            'amount' => $amount,
            'paid_at' => $paidAt,
            'bank_name' => $payload['bank_name'] ?? ($method === 'cheque' ? ($payload['cheque_bank_name'] ?? null) : null),
            'payment_identifier' => $method === 'cheque'
                ? ($payload['cheque_number'] ?? $payload['payment_identifier'] ?? null)
                : ($payload['payment_identifier'] ?? null),
            'receipt_image' => $receiptImagePath,
            'note' => $method === 'cheque' ? null : ($payload['note'] ?? null),
        ]);

        if ($method === 'cheque') {
            Cheque::create([
                'invoice_payment_id' => $payment->id,
                'bank_name' => $payload['bank_name'] ?? ($payload['cheque_bank_name'] ?? null),
                'branch_name' => null,
                'cheque_number' => $payload['cheque_number'] ?? null,
                'amount' => $amount,
                'due_date' => $payload['due_date'] ?? ($payload['cheque_due_date'] ?? null),
                'received_at' => $payload['received_at'] ?? ($payload['cheque_received_at'] ?? null),
                'customer_name' => null,
                'customer_code' => null,
                'account_number' => null,
                'account_holder' => null,
                'image' => null,
                'status' => $payload['cheque_status'] ?? 'pending',
            ]);
        }

        $ledgerCustomerId = (int) ($payment->customer_id ?: 0);
        if ($ledgerCustomerId > 0) {
            CustomerLedger::create([
                'customer_id' => $ledgerCustomerId,
                'type' => 'credit',
                'amount' => (int) $payment->amount,
                'reference_type' => InvoicePayment::class,
                'reference_id' => $payment->id,
                'note' => 'ثبت پرداخت برای فاکتور ' . $invoice->uuid . ' (' . $this->methodLabel($method) . ')',
            ]);
        }

        return $payment;
    }

    public function methodLabel(string $method): string
    {
        return match ($method) {
            'cash' => 'نقدی',
            'cheque' => 'چکی',
            default => $method,
        };
    }
}
