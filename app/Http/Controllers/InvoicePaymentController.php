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
        ]);

        $invoice = Invoice::query()->findOrFail((int) $data['invoice_id']);

        [$payment, $remainingBefore, $remainingAfter] = DB::transaction(function () use ($invoice, $data, $request, $customer) {
            $invoice = Invoice::query()->whereKey($invoice->id)->lockForUpdate()->firstOrFail();
            $invoice->assertNotCancelled();
            $remainingBefore = $this->remainingAmount($invoice);
            abort_unless((int) $invoice->customer_id === (int) $customer->id, 404);
            $payment = $this->createPaymentRecord($request, $invoice, $customer->id, $remainingBefore);

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

    public function editForCustomer(Customer $customer, InvoicePayment $payment)
    {
        abort_unless($this->canHandleFinanceActions(), 403);
        abort_unless((int) $payment->customer_id === (int) $customer->id, 404);
        $payment->load('cheque', 'invoice');
        abort_unless($payment->invoice && (int) $payment->invoice->customer_id === (int) $customer->id, 404);

        $ledgers = CustomerLedger::query()->where('reference_type', InvoicePayment::class)->where('reference_id', $payment->id)->get();
        $ledger = $ledgers->first();
        $managementIssue = $ledgers->count() !== 1 || (int) $ledger->customer_id !== (int) $customer->id
            || $ledger->type !== 'credit' || (int) $ledger->amount !== (int) $payment->amount
            || $payment->cheque()->count() > 1;
        return view('payments.edit', [
            'customer' => $customer, 'payment' => $payment,
            'version' => $this->paymentVersion($payment), 'managementIssue' => $managementIssue,
        ]);
    }

    public function updateForCustomer(Customer $customer, InvoicePayment $payment, Request $request)
    {
        abort_unless($this->canHandleFinanceActions(), 403);
        abort_unless((int) $payment->customer_id === (int) $customer->id, 404);
        $data = $request->validate([
            'method' => ['required', Rule::in(array_unique(['cash', 'cheque', $payment->method]))],
            'amount' => 'required|integer|min:1',
            'payment_date' => 'required_unless:method,cheque|nullable|string|max:20',
            'bank_name' => 'nullable|string|max:255',
            'tracking_number' => 'nullable|string|max:190',
            'note' => 'nullable|string|max:2000',
            'cheque_number' => 'required_if:method,cheque|nullable|string|max:255',
            'cheque_branch_name' => 'nullable|string|max:255',
            'due_date' => 'required_if:method,cheque|nullable|string|max:20',
            'received_date' => 'required_if:method,cheque|nullable|string|max:20',
            'cheque_customer_name' => 'nullable|string|max:255',
            'cheque_account_number' => 'nullable|string|max:255',
            'cheque_account_holder' => 'nullable|string|max:255',
            'cheque_status' => ['nullable', Rule::in(array_unique(['registered', 'unregistered', $payment->cheque?->status ?? 'unregistered']))],
            'reason' => 'required|string|min:5|max:1000',
            'version' => 'required|string|size:64',
        ]);

        DB::transaction(function () use ($customer, $payment, $data) {
            [$invoice, $payment, $ledger] = $this->lockPayment($customer, $payment, $data['version']);
            $invoice->assertNotCancelled();
            $before = $this->paymentSnapshot($payment, $payment->cheque);
            $data = $this->normalizeEditPaymentPayload($data, $invoice);
            $payment->update([
                'method' => $data['method'], 'amount' => (int) $data['amount'],
                'paid_at' => $data['paid_at'], 'bank_name' => $data['bank_name'] ?? null,
                'payment_identifier' => $data['method'] === 'cheque' ? $data['cheque_number'] : ($data['payment_identifier'] ?? null),
                'note' => $data['note'] ?? null,
            ]);
            if ($data['method'] === 'cheque') {
                $payment->cheque()->updateOrCreate([], [
                    'bank_name' => $data['bank_name'] ?? null,
                    'branch_name' => $data['cheque_branch_name'] ?? null,
                    'cheque_number' => $data['cheque_number'], 'amount' => (int) $data['amount'],
                    'due_date' => $data['cheque_due_date'], 'received_at' => $data['cheque_received_at'],
                    'customer_name' => $data['cheque_customer_name'] ?? null,
                    'account_number' => $data['cheque_account_number'] ?? null,
                    'account_holder' => $data['cheque_account_holder'] ?? null,
                    'status' => $data['cheque_status'] ?? $payment->cheque?->status ?? 'unregistered',
                ]);
            } else {
                $payment->cheque()->delete();
            }
            $ledger->update(['amount' => (int) $payment->amount,
                'note' => 'ثبت پرداخت برای فاکتور '.$invoice->uuid.' ('.$this->methodLabel($payment->method).')']);
            $payment->unsetRelation('cheque')->load('cheque');
            ActivityLogger::log('invoice_payment_updated', $invoice, 'پرداخت مشتری ویرایش شد.', [
                'payment_id' => $payment->id, 'customer_id' => $customer->id,
                'before' => $before, 'after' => $this->paymentSnapshot($payment, $payment->cheque),
                'reason' => trim($data['reason']),
            ]);
        });
        return $this->paymentReturn($customer, $payment->invoice->uuid)->with('success', 'پرداخت و مانده حساب به‌روزرسانی شد.');
    }

    public function destroyForCustomer(Customer $customer, InvoicePayment $payment, Request $request)
    {
        abort_unless($this->canHandleFinanceActions(), 403);
        abort_unless((int) $payment->customer_id === (int) $customer->id, 404);
        $data = $request->validate(['reason' => 'required|string|min:5|max:1000', 'version' => 'required|string|size:64']);
        $uuid = DB::transaction(function () use ($customer, $payment, $data) {
            [$invoice, $payment, $ledger] = $this->lockPayment($customer, $payment, $data['version']);
            $before = $this->paymentSnapshot($payment, $payment->cheque);
            $ledger->delete();
            $payment->cheque()->delete();
            $payment->delete();
            ActivityLogger::log('invoice_payment_deleted', $invoice, 'پرداخت مشتری حذف شد.', [
                'payment_id' => $before['id'], 'customer_id' => $customer->id,
                'before' => $before, 'reason' => trim($data['reason']),
            ]);
            return $invoice->uuid;
        });
        return $this->paymentReturn($customer, $uuid)->with('success', 'پرداخت حذف شد و مانده حساب به‌روزرسانی شد.');
    }

    private function paymentVersion(InvoicePayment $payment): string
    {
        return hash('sha256', json_encode($this->paymentSnapshot($payment, $payment->cheque), JSON_THROW_ON_ERROR));
    }

    private function lockPayment(Customer $customer, InvoicePayment $original, string $version): array
    {
        // Registration also locks the invoice first: use one lock order for all payment writes.
        $invoice = Invoice::query()->whereKey($original->invoice_id)->lockForUpdate()->firstOrFail();
        $payment = InvoicePayment::query()->whereKey($original->id)->lockForUpdate()->firstOrFail();
        abort_unless((int) $payment->customer_id === (int) $customer->id
            && (int) $invoice->customer_id === (int) $customer->id
            && (int) $payment->invoice_id === (int) $invoice->id, 404);
        $cheques = $payment->cheque()->lockForUpdate()->get();
        if ($cheques->count() > 1) {
            throw ValidationException::withMessages(['payment' => 'برای این پرداخت چند چک ثبت شده است؛ ابتدا سوابق مالی بررسی شود.']);
        }
        $payment->setRelation('cheque', $cheques->first());
        if (!hash_equals($this->paymentVersion($payment), $version)) {
            throw ValidationException::withMessages(['version' => 'این پرداخت تغییر کرده است؛ صفحه را دوباره باز کنید.']);
        }
        $ledgers = CustomerLedger::query()->where('reference_type', InvoicePayment::class)
            ->where('reference_id', $payment->id)->lockForUpdate()->get();
        $ledger = $ledgers->first();
        if ($ledgers->count() !== 1 || (int) $ledger->customer_id !== (int) $customer->id
            || $ledger->type !== 'credit' || (int) $ledger->amount !== (int) $payment->amount) {
            throw ValidationException::withMessages(['payment' => 'سند مالی این پرداخت نیاز به بررسی دارد؛ تغییری ثبت نشد.']);
        }
        return [$invoice, $payment, $ledger];
    }

    private function paymentReturn(Customer $customer, string $uuid)
    {
        if (request('return_to') === 'account-statement') {
            return redirect()->route('account-statements.show', $customer->id);
        }

        return \App\Support\PageAccessCatalog::userCanRoute(auth()->user(), 'invoices.show')
            ? redirect()->route('invoices.show', $uuid)
            : redirect()->route('account-statements.show', $customer->id);
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
            'cheque' => $cheque?->only(['cheque_number', 'bank_name', 'branch_name', 'amount', 'due_date', 'received_at', 'customer_name', 'customer_code', 'account_number', 'account_holder', 'image', 'status']),
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


        return $this->persistPayment($invoice, $data, $fallbackCustomerId);
    }

    private function normalizeEditPaymentPayload(array $data, Invoice $invoice): array
    {
        $receivedAt = $data['cheque_received_at'] ?? $this->normalizeDate($data['received_date'] ?? null, 'received_date');
        $dueDate = $data['cheque_due_date'] ?? $this->normalizeDate($data['due_date'] ?? null, 'due_date');
        if (($data['method'] ?? 'cash') === 'cheque' && (! $receivedAt || ! $dueDate)) {
            throw ValidationException::withMessages(['received_date' => 'تاریخ دریافت و تاریخ سررسید چک الزامی است.']);
        }
        $data['paid_at'] = ($data['method'] ?? 'cash') === 'cheque'
            ? $receivedAt
            : ($data['paid_at'] ?? $this->normalizeDate($data['payment_date'] ?? null, 'payment_date') ?? now()->toDateString());
        $data['payment_identifier'] = $data['payment_identifier'] ?? $data['tracking_number'] ?? null;
        $data['note'] = $data['note'] ?? $data['description'] ?? null;
        $data['cheque_bank_name'] = $data['cheque_bank_name'] ?? $data['bank_name'] ?? null;
        $data['cheque_branch_name'] = $data['cheque_branch_name'] ?? $data['branch_name'] ?? null;
        $data['cheque_due_date'] = $dueDate;
        $data['due_date'] = $dueDate;
        $data['cheque_received_at'] = $receivedAt;
        $data['cheque_customer_name'] = $data['cheque_customer_name'] ?? $data['cheque_owner_name'] ?? $invoice->customer_name ?? $invoice->customer?->display_name ?? null;
        $data['cheque_customer_code'] = $data['cheque_customer_code'] ?? $data['customer_code'] ?? $invoice->customer?->crm_customer_id ?? $invoice->customer_id ?? null;
        $data['cheque_amount'] = $data['cheque_amount'] ?? $data['amount'];

        return $data;
    }

    private function normalizeDate(?string $value, string $field): ?string
    {
        if (blank($value)) return null;
        $normalized = strtr(trim($value), ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9','-'=>'/','.'=>'/']);
        $date = null;
        if (preg_match('/^1[34]\d{2}\/\d{1,2}\/\d{1,2}$/', $normalized)) {
            $date = \App\Support\JalaliDate::toGregorianDate($normalized);
        } elseif (preg_match('/^(\d{4})\/(\d{1,2})\/(\d{1,2})$/', $normalized, $parts)
            && (int) $parts[1] >= 1900 && checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            $date = sprintf('%04d-%02d-%02d', $parts[1], $parts[2], $parts[3]);
        }
        if (!$date) throw ValidationException::withMessages([$field => 'تاریخ واردشده معتبر نیست.']);
        return $date;
    }

    private function remainingAmount(Invoice $invoice): int
    {
        $paid = (int) $invoice->payments()->sum('amount');

        return max((int) $invoice->total - $paid, 0);
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

        return $user && ! \App\Support\AccountStatementAccess::readOnly($user)
            && ($user->hasAnyRole(['admin', 'Admin', 'Manager', 'manager', 'finance', 'Accountant']) || $user->can('finance.approve'));
    }
}
