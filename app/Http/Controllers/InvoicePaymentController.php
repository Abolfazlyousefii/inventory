<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Services\PaymentRegistrationService;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Morilog\Jalali\Jalalian;

class InvoicePaymentController extends Controller
{
    public function __construct(private readonly PaymentRegistrationService $paymentService)
    {
    }

    public function store(string $uuid, Request $request)
    {
        abort_unless($this->canHandleFinanceActions(), 403);

        $invoice = Invoice::query()->where('uuid', $uuid)->firstOrFail();

        [$payment, $remainingBefore, $remainingAfter] = DB::transaction(function () use ($request, $invoice) {
            $invoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $remainingBefore = $this->remainingAmount($invoice);
            $payment = $this->createPaymentRecord($request, $invoice, $invoice->customer_id ? (int) $invoice->customer_id : null, $remainingBefore);

            return [$payment, $remainingBefore, max($remainingBefore - (int) $payment->amount, 0)];
        });

        ActivityLogger::log('invoice_payment_added', $invoice->fresh(), 'پرداخت از صفحه فاکتور ثبت شد.', [
            'payment_id' => $payment->id,
            'invoice_id' => $invoice->id,
            'customer_id' => $invoice->customer_id,
            'amount' => (int) $payment->amount,
            'remaining_before' => $remainingBefore,
            'remaining_after' => $remainingAfter,
            'method' => $payment->method,
            'source' => 'invoice_page',
        ]);

        return back()->with('success', "✅ پرداخت {$this->methodLabel($payment->method)} با موفقیت ثبت شد.");
    }

    public function storeForCustomer(Customer $customer, Request $request)
    {
        abort_unless($this->canHandleFinanceActions(), 403);

        $data = $request->validate([
            'invoice_id' => [
                'required',
                'integer',
                Rule::exists('invoices', 'id')->where(fn ($q) => $q->where('customer_id', $customer->id)),
            ],
            'method' => 'required|in:cash,cheque',
            'amount' => 'required|integer|min:1',
            'payment_date' => 'required_without:paid_at|string|max:20',
            'paid_at' => 'nullable|date',
            'bank_name' => 'nullable|string|max:255',
            'tracking_number' => 'nullable|string|max:190',
            'payment_identifier' => 'nullable|string|max:190',
            'description' => 'nullable|string|max:2000',
            'note' => 'nullable|string|max:2000',
            'receipt_image' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:4096',
            'cheque_bank_name' => 'nullable|string|max:255',
            'branch_name' => 'nullable|string|max:255',
            'cheque_branch_name' => 'nullable|string|max:255',
            'cheque_number' => 'required_if:method,cheque|nullable|string|max:255',
            'cheque_amount' => 'nullable|integer|min:1',
            'due_date' => 'required_if:method,cheque|nullable|string|max:20',
            'cheque_due_date' => 'nullable|date',
            'received_date' => 'nullable|string|max:20',
            'cheque_received_at' => 'nullable|date',
            'cheque_owner_name' => 'nullable|string|max:255',
            'cheque_customer_name' => 'nullable|string|max:255',
            'customer_code' => 'nullable|string|max:255',
            'cheque_customer_code' => 'nullable|string|max:255',
            'cheque_account_number' => 'nullable|string|max:255',
            'cheque_account_holder' => 'nullable|string|max:255',
            'cheque_status' => 'nullable|in:pending,passed,bounced,cancelled,cleared,registered,unregistered',
            'cheque_image' => 'nullable|image|max:4096',
        ]);

        $invoice = Invoice::query()->findOrFail((int) $data['invoice_id']);

        [$payment, $remainingBefore, $remainingAfter] = DB::transaction(function () use ($invoice, $data, $request, $customer) {
            $invoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $remainingBefore = $this->remainingAmount($invoice);
            $data = $this->normalizeEditPaymentPayload($data, $invoice);
            $this->assertPaymentDoesNotExceedRemaining((int) $data['amount'], $remainingBefore);
            $payment = $this->persistPayment($invoice, $data, $request, $customer->id);

            return [$payment, $remainingBefore, max($remainingBefore - (int) $payment->amount, 0)];
        });

        ActivityLogger::log('invoice_payment_added', $invoice->fresh(), 'پرداخت از گردش حساب مشتری ثبت شد.', [
            'payment_id' => $payment->id,
            'invoice_id' => $invoice->id,
            'customer_id' => $customer->id,
            'amount' => (int) $payment->amount,
            'remaining_before' => $remainingBefore,
            'remaining_after' => $remainingAfter,
            'method' => $payment->method,
            'source' => 'account_statement',
        ]);

        return back()->with('success', "✅ پرداخت {$this->methodLabel($payment->method)} برای مشتری ثبت شد.");
    }

    private function createPaymentRecord(Request $request, Invoice $invoice, ?int $fallbackCustomerId = null, ?int $remainingBefore = null): InvoicePayment
    {
        $data = $request->validate([
            'method' => 'required|in:cash,cheque',
            'amount' => 'required|integer|min:1',
            'payment_date' => 'required_without:paid_at|string|max:20',
            'paid_at' => 'nullable|date',
            'bank_name' => 'nullable|string|max:255',
            'tracking_number' => 'nullable|string|max:190',
            'payment_identifier' => 'nullable|string|max:190',
            'description' => 'nullable|string|max:2000',
            'note' => 'nullable|string|max:2000',
            'receipt_image' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:4096',
            'cheque_bank_name' => 'nullable|string|max:255',
            'branch_name' => 'nullable|string|max:255',
            'cheque_branch_name' => 'nullable|string|max:255',
            'cheque_number' => 'required_if:method,cheque|nullable|string|max:255',
            'cheque_amount' => 'nullable|integer|min:1',
            'due_date' => 'required_if:method,cheque|nullable|string|max:20',
            'cheque_due_date' => 'nullable|date',
            'received_date' => 'nullable|string|max:20',
            'cheque_received_at' => 'nullable|date',
            'cheque_owner_name' => 'nullable|string|max:255',
            'cheque_customer_name' => 'nullable|string|max:255',
            'customer_code' => 'nullable|string|max:255',
            'cheque_customer_code' => 'nullable|string|max:255',
            'cheque_account_number' => 'nullable|string|max:255',
            'cheque_account_holder' => 'nullable|string|max:255',
            'cheque_status' => 'nullable|in:pending,passed,bounced,cancelled,cleared,registered,unregistered',
            'cheque_image' => 'nullable|image|max:4096',
        ]);

        $data = $this->normalizeEditPaymentPayload($data, $invoice);

        $this->assertPaymentDoesNotExceedRemaining((int) $data['amount'], $remainingBefore ?? $this->remainingAmount($invoice));

        return $this->persistPayment($invoice, $data, $request, $fallbackCustomerId);
    }

    private function normalizeEditPaymentPayload(array $data, Invoice $invoice): array
    {
        $data['paid_at'] = $data['paid_at'] ?? $this->normalizeDate($data['payment_date'] ?? null) ?? now()->toDateString();
        $data['payment_identifier'] = $data['payment_identifier'] ?? $data['tracking_number'] ?? null;
        $data['note'] = $data['note'] ?? $data['description'] ?? null;
        $data['cheque_bank_name'] = $data['cheque_bank_name'] ?? $data['bank_name'] ?? null;
        $data['cheque_branch_name'] = $data['cheque_branch_name'] ?? $data['branch_name'] ?? null;
        $data['cheque_due_date'] = $data['cheque_due_date'] ?? $this->normalizeDate($data['due_date'] ?? null) ?? null;
        $data['cheque_received_at'] = $data['cheque_received_at'] ?? $this->normalizeDate($data['received_date'] ?? null) ?? null;
        $data['cheque_customer_name'] = $data['cheque_customer_name'] ?? $data['cheque_owner_name'] ?? $invoice->customer_name ?? $invoice->customer?->display_name ?? null;
        $data['cheque_customer_code'] = $data['cheque_customer_code'] ?? $data['customer_code'] ?? $invoice->customer?->crm_customer_id ?? $invoice->customer_id ?? null;
        $data['cheque_amount'] = $data['cheque_amount'] ?? $data['amount'];

        return $data;
    }

    private function normalizeDate(?string $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        $normalized = str_replace(['-', '.'], '/', $value);
        if (preg_match('/^1[34]\d{2}\/\d{1,2}\/\d{1,2}$/', $normalized)) {
            return Jalalian::fromFormat('Y/m/d', $normalized)->toCarbon()->toDateString();
        }

        return $value;
    }

    private function remainingAmount(Invoice $invoice): int
    {
        $paid = (int) $invoice->payments()->sum('amount');

        return max((int) $invoice->total - $paid, 0);
    }

    private function assertPaymentDoesNotExceedRemaining(int $amount, int $remaining): void
    {
        if ($amount > $remaining) {
            abort(422, 'مبلغ پرداخت نمی‌تواند بیشتر از مانده فاکتور باشد.');
        }
    }

    private function persistPayment(Invoice $invoice, array $data, Request $request, ?int $fallbackCustomerId = null): InvoicePayment
    {
        $path = null;
        if ($request->hasFile('receipt_image')) {
            $path = $request->file('receipt_image')->store('invoices/receipts', 'public');
        }

        $chequeImagePath = null;
        if ($request->hasFile('cheque_image')) {
            $chequeImagePath = $request->file('cheque_image')->store('invoices/cheques', 'public');
        }

        return $this->paymentService->registerForInvoice(
            $invoice,
            $data,
            $fallbackCustomerId,
            auth()->id(),
            $path,
            $chequeImagePath
        );
    }

    private function methodLabel(string $method): string
    {
        return $this->paymentService->methodLabel($method);
    }

    private function canHandleFinanceActions(): bool
    {
        $user = auth()->user();

        return $user && ($user->hasAnyRole(['admin', 'Admin', 'Manager', 'manager', 'finance', 'Accountant']) || $user->can('finance.approve'));
    }
}
