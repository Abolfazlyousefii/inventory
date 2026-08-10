<?php

namespace App\Http\Controllers;

use App\Models\Cheque;
use App\Models\InvoicePayment;
use Illuminate\Http\Request;

class ChequeController extends Controller
{
    public function index(Request $request)
    {
        $query = Cheque::query()
            ->with(['payment.invoice']);

        if ($request->filled('status')) {
            $query->where('status', $request->input('status'));
        }

        if ($request->filled('customer_name')) {
            $customerName = trim($request->input('customer_name'));

            $query->where(function ($q) use ($customerName) {
                $q->where('customer_name', 'like', "%{$customerName}%")
                    ->orWhereHas('payment.invoice', function ($invoiceQ) use ($customerName) {
                        $invoiceQ->where('customer_name', 'like', "%{$customerName}%");
                    });
            });
        }

        if ($request->filled('cheque_number')) {
            $chequeNumber = trim($request->input('cheque_number'));

            $query->where('cheque_number', 'like', "%{$chequeNumber}%");
        }

        // از تاریخ دریافت
        if ($request->filled('received_from')) {
            $query->whereDate('received_at', '>=', $request->input('received_from'));
        }

        // تا تاریخ دریافت
        if ($request->filled('received_to')) {
            $query->whereDate('received_at', '<=', $request->input('received_to'));
        }

        // از تاریخ سررسید
        if ($request->filled('due_from')) {
            $query->whereDate('due_date', '>=', $request->input('due_from'));
        }

        // تا تاریخ سررسید
        if ($request->filled('due_to')) {
            $query->whereDate('due_date', '<=', $request->input('due_to'));
        }

        $cheques = $query
            ->orderByRaw('received_at IS NULL')
            ->orderByDesc('received_at')
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('finance.registered-cheques-index', compact('cheques'));
    }

    public function store(InvoicePayment $payment, Request $request)
    {
        abort_unless($this->canHandleFinanceActions(), 403);
        abort_if($payment->method !== 'cheque', 403);

        $data = $request->validate([
            'bank_name' => 'nullable|string|max:255',
            'branch_name' => 'nullable|string|max:255',
            'cheque_number' => 'nullable|string|max:255',
            'amount' => 'nullable|integer|min:0',
            'due_date' => 'nullable|date',
            'received_at' => 'nullable|date',
            'customer_name' => 'nullable|string|max:255',
            'customer_code' => 'nullable|string|max:255',
            'account_number' => 'nullable|string|max:255',
            'account_holder' => 'nullable|string|max:255',
            'image' => 'nullable|image|max:4096',
            'status' => 'nullable|in:pending,cleared,bounced,registered,unregistered',
        ]);

        $path = null;

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('invoices/cheques', 'public');
        }

        $invoice = $payment->invoice;

        $payment->cheque()->create([
            'bank_name' => $data['bank_name'] ?? null,
            'branch_name' => $data['branch_name'] ?? null,
            'cheque_number' => $data['cheque_number'] ?? null,
            'amount' => $data['amount'] ?? $payment->amount ?? 0,
            'due_date' => $data['due_date'] ?? null,
            'received_at' => $data['received_at'] ?? now()->toDateString(),
            'customer_name' => $data['customer_name'] ?? $invoice?->customer_name,
            'customer_code' => $data['customer_code'] ?? null,
            'account_number' => $data['account_number'] ?? null,
            'account_holder' => $data['account_holder'] ?? null,
            'image' => $path,
            'status' => $data['status'] ?? 'unregistered',
        ]);

        return back()->with('success', '✅ چک ثبت شد.');
    }

    private function canHandleFinanceActions(): bool
    {
        $user = auth()->user();

        return $user && (
            $user->hasAnyRole(['admin', 'finance']) ||
            $user->can('finance.approve')
        );
    }
}