<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\CustomerLedger;
use App\Services\PaymentRegistrationService;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
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
            $invoice->assertNotCancelled();
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
            'payment_date' => 'nullable|string|max:20',
            'paid_at' => 'nullable|date',
            'bank_name' => 'nullable|string|max:255',
            'tracking_number' => 'nullable|string|max:190',
            'payment_identifier' => 'nullable|string|max:190',
            'description' => 'nullable|string|max:2000',
            'note' => 'nullable|string|max:2000',
            'cheque_bank_name' => 'nullable|string|max:255',
            'branch_name' => 'nullable|string|max:255',
            'cheque_branch_name' => 'nullable|string|max:255',
            'cheque_number' => 'required_if:method,cheque|nullable|string|max:255',
            'cheque_amount' => 'nullable|integer|min:1',
            'due_date' => 'nullable|string|max:20',
            'cheque_due_date' => 'nullable|date',
            'received_date' => 'nullable|string|max:20',
            'cheque_received_at' => 'nullable|date',
            'cheque_owner_name' => 'nullable|string|max:255',
            'cheque_customer_name' => 'nullable|string|max:255',
            'customer_code' => 'nullable|string|max:255',
            'cheque_customer_code' => 'nullable|string|max:255',
            'cheque_account_number' => 'nullable|string|max:255',
            'cheque_account_holder' => 'nullable|string|max:255',
            'cheque_status' => 'nullable|in:registered,unregistered',
        ]);

        $invoice = Invoice::query()->findOrFail((int) $data['invoice_id']);

        [$payment, $remainingBefore, $remainingAfter] = DB::transaction(function () use ($invoice, $data, $request, $customer) {
            $invoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $invoice->assertNotCancelled();
            $remainingBefore = $this->remainingAmount($invoice);
            $data = $this->normalizeEditPaymentPayload($data, $invoice);
            $this->assertPaymentDoesNotExceedRemaining((int) $data['amount'], $remainingBefore);
            $payment = $this->persistPayment($invoice, $data, $customer->id);

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

    public function updateForCustomer(Customer $customer, InvoicePayment $payment, Request $request)
    {
        abort_unless($this->canHandleFinanceActions(), 403);
        abort_unless((int) $payment->customer_id === (int) $customer->id, 404);

        $data = $request->validate([
            'invoice_id' => ['required', 'integer', Rule::exists('invoices', 'id')->where(fn ($q) => $q->where('customer_id', $customer->id))],
            'method' => 'required|in:cash,cheque',
            'amount' => 'required|integer|min:1',
            'paid_at' => 'nullable|date',
            'bank_name' => 'nullable|string|max:255',
            'note' => 'nullable|string|max:2000',
            'cheque_number' => 'required_if:method,cheque|nullable|string|max:255',
            'cheque_bank_name' => 'nullable|string|max:255',
            'cheque_branch_name' => 'nullable|string|max:255',
            'cheque_due_date' => 'nullable|date',
            'cheque_received_at' => 'nullable|date',
            'cheque_customer_name' => 'nullable|string|max:255',
            'cheque_account_number' => 'nullable|string|max:255',
            'cheque_account_holder' => 'nullable|string|max:255',
            'cheque_status' => 'nullable|in:registered,unregistered',
            'reason' => 'required|string|min:5|max:1000',
        ]);

        DB::transaction(function () use ($customer, $payment, $data) {
            $payment = InvoicePayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $payment->customer_id === (int) $customer->id, 404);
            $oldCheque = $payment->cheque;
            $before = $this->paymentSnapshot($payment, $oldCheque);
            $invoiceIds = collect([$payment->invoice_id, (int) $data['invoice_id']])->unique()->sort()->values();
            $invoices = Invoice::query()->whereIn('id', $invoiceIds)->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $invoice = $invoices->get((int) $data['invoice_id']);
            abort_unless($invoice && (int) $invoice->customer_id === (int) $customer->id, 404);
            $invoice->assertNotCancelled();
            $paidByOthers = (int) $invoice->payments()->where('id', '!=', $payment->id)->sum('amount');
            $this->assertPaymentDoesNotExceedRemaining((int) $data['amount'], max((int) $invoice->total - $paidByOthers, 0));

            $data = $this->normalizeEditPaymentPayload($data, $invoice);
            $payment->update([
                'invoice_id' => $invoice->id,
                'method' => $data['method'],
                'amount' => (int) $data['amount'],
                'paid_at' => $data['paid_at'],
                'bank_name' => $data['method'] === 'cheque' ? ($data['cheque_bank_name'] ?? null) : ($data['bank_name'] ?? null),
                'payment_identifier' => $data['method'] === 'cheque' ? $data['cheque_number'] : null,
                'note' => $data['method'] === 'cheque' ? null : ($data['note'] ?? null),
            ]);

            if ($data['method'] === 'cheque') {
                $payment->cheque()->updateOrCreate([], [
                    'bank_name' => $data['cheque_bank_name'] ?? null,
                    'branch_name' => $data['cheque_branch_name'] ?? null,
                    'cheque_number' => $data['cheque_number'],
                    'amount' => (int) $data['amount'],
                    'due_date' => $data['cheque_due_date'],
                    'received_at' => $data['cheque_received_at'],
                    'customer_name' => $data['cheque_customer_name'] ?? null,
                    'account_number' => $data['cheque_account_number'] ?? null,
                    'account_holder' => $data['cheque_account_holder'] ?? null,
                    'status' => $data['cheque_status'] ?? 'unregistered',
                ]);
            } else {
                $payment->cheque()->delete();
            }

            CustomerLedger::query()->where('reference_type', InvoicePayment::class)->where('reference_id', $payment->id)
                ->where('customer_id', $customer->id)->update([
                    'amount' => (int) $payment->amount,
                    'note' => 'ثبت پرداخت برای فاکتور '.$invoice->uuid.' ('.$this->methodLabel($payment->method).')',
                ]);

            $payment->unsetRelation('cheque')->load('cheque');
            ActivityLogger::log('invoice_payment_updated', $invoice, 'پرداخت مشتری ویرایش شد.', [
                'payment_id' => $payment->id,
                'customer_id' => $customer->id,
                'before' => $before,
                'after' => $this->paymentSnapshot($payment, $payment->cheque),
                'reason' => trim($data['reason']),
            ]);
        });

        return redirect()->route('account-statements.show', $customer)->with('success', 'پرداخت و گردش حساب به‌روزرسانی شد.');
    }

    public function destroyForCustomer(Customer $customer, InvoicePayment $payment, Request $request)
    {
        abort_unless($this->canHandleFinanceActions(), 403);
        abort_unless((int) $payment->customer_id === (int) $customer->id, 404);
        $data = $request->validate(['reason' => 'required|string|min:5|max:1000']);

        DB::transaction(function () use ($customer, $payment, $data) {
            $payment = InvoicePayment::query()->whereKey($payment->id)->lockForUpdate()->firstOrFail();
            abort_unless((int) $payment->customer_id === (int) $customer->id, 404);
            $invoice = Invoice::query()->whereKey($payment->invoice_id)->lockForUpdate()->firstOrFail();
            $before = $this->paymentSnapshot($payment, $payment->cheque);

            CustomerLedger::query()->where('reference_type', InvoicePayment::class)->where('reference_id', $payment->id)
                ->where('customer_id', $customer->id)->delete();
            $payment->cheque()->delete();
            $payment->delete();

            ActivityLogger::log('invoice_payment_deleted', $invoice, 'پرداخت مشتری حذف شد.', [
                'payment_id' => $before['id'],
                'customer_id' => $customer->id,
                'before' => $before,
                'reason' => trim($data['reason']),
            ]);
        });

        return redirect()->route('account-statements.show', $customer)->with('success', 'پرداخت حذف شد و مانده حساب به‌روزرسانی شد.');
    }

    private function paymentSnapshot(InvoicePayment $payment, ?\App\Models\Cheque $cheque): array
    {
        return [
            'id' => $payment->id,
            'invoice_id' => $payment->invoice_id,
            'customer_id' => $payment->customer_id,
            'created_by' => $payment->created_by,
            'method' => $payment->method,
            'amount' => (int) $payment->amount,
            'paid_at' => $payment->paid_at,
            'bank_name' => $payment->bank_name,
            'payment_identifier' => $payment->payment_identifier,
            'receipt_image' => $payment->receipt_image,
            'note' => $payment->note,
            'cheque' => $cheque?->only(['cheque_number', 'bank_name', 'branch_name', 'amount', 'due_date', 'received_at', 'customer_name', 'account_number', 'account_holder', 'image', 'status']),
        ];
    }

    private function createPaymentRecord(Request $request, Invoice $invoice, ?int $fallbackCustomerId = null, ?int $remainingBefore = null): InvoicePayment
    {
        $data = $request->validate([
            'method' => 'required|in:cash,cheque',
            'amount' => 'required|integer|min:1',
            'payment_date' => 'nullable|string|max:20',
            'paid_at' => 'nullable|date',
            'bank_name' => 'nullable|string|max:255',
            'tracking_number' => 'nullable|string|max:190',
            'payment_identifier' => 'nullable|string|max:190',
            'description' => 'nullable|string|max:2000',
            'note' => 'nullable|string|max:2000',
            'cheque_bank_name' => 'nullable|string|max:255',
            'branch_name' => 'nullable|string|max:255',
            'cheque_branch_name' => 'nullable|string|max:255',
            'cheque_number' => 'required_if:method,cheque|nullable|string|max:255',
            'cheque_amount' => 'nullable|integer|min:1',
            'due_date' => 'nullable|string|max:20',
            'cheque_due_date' => 'nullable|date',
            'received_date' => 'nullable|string|max:20',
            'cheque_received_at' => 'nullable|date',
            'cheque_owner_name' => 'nullable|string|max:255',
            'cheque_customer_name' => 'nullable|string|max:255',
            'customer_code' => 'nullable|string|max:255',
            'cheque_customer_code' => 'nullable|string|max:255',
            'cheque_account_number' => 'nullable|string|max:255',
            'cheque_account_holder' => 'nullable|string|max:255',
            'cheque_status' => 'nullable|in:registered,unregistered',
        ]);

        $data = $this->normalizeEditPaymentPayload($data, $invoice);

        $this->assertPaymentDoesNotExceedRemaining((int) $data['amount'], $remainingBefore ?? $this->remainingAmount($invoice));

        return $this->persistPayment($invoice, $data, $fallbackCustomerId);
    }

    private function normalizeEditPaymentPayload(array $data, Invoice $invoice): array
    {
        $receivedAt = $data['cheque_received_at'] ?? $this->normalizeDate($data['received_date'] ?? null);
        $dueDate = $data['cheque_due_date'] ?? $this->normalizeDate($data['due_date'] ?? null);
        if (($data['method'] ?? 'cash') === 'cheque' && (! $receivedAt || ! $dueDate)) {
            throw ValidationException::withMessages(['received_date' => 'تاریخ دریافت و تاریخ سررسید چک الزامی است.']);
        }
        $data['paid_at'] = ($data['method'] ?? 'cash') === 'cheque'
            ? $receivedAt
            : ($data['paid_at'] ?? $this->normalizeDate($data['payment_date'] ?? null) ?? now()->toDateString());
        $data['payment_identifier'] = $data['payment_identifier'] ?? $data['tracking_number'] ?? null;
        $data['note'] = $data['note'] ?? $data['description'] ?? null;
        $data['cheque_bank_name'] = $data['cheque_bank_name'] ?? $data['bank_name'] ?? null;
        $data['cheque_branch_name'] = $data['cheque_branch_name'] ?? $data['branch_name'] ?? null;
        $data['cheque_due_date'] = $dueDate;
        $data['cheque_received_at'] = $receivedAt;
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

    private function persistPayment(Invoice $invoice, array $data, ?int $fallbackCustomerId = null): InvoicePayment
    {
        return $this->paymentService->registerForInvoice(
            $invoice,
            $data,
            $fallbackCustomerId,
            auth()->id(),
            null,
            null
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
