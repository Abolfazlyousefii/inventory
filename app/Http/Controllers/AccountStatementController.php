<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\Purchase;
use App\Models\WarehouseTransfer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class AccountStatementController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        $normalizedSearch = $this->normalizeSearchTerm($q);
        $numericSearch = preg_replace('/\D+/', '', $normalizedSearch);

        $customers = Customer::query()
            ->with('city:id,name')
            ->withBalance()
            ->when($q !== '', function ($query) use ($q, $normalizedSearch, $numericSearch) {
                $like = "%{$q}%";
                $normalizedLike = "%{$normalizedSearch}%";

                $query->where(function ($nested) use ($like, $normalizedLike, $numericSearch) {
                    $nested->where('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhereRaw($this->fullNameExpression() . ' LIKE ?', [$like])
                        ->orWhere('mobile', 'like', $like)
                        ->orWhere('crm_customer_id', 'like', $like)
                        ->orWhereHas('city', function ($cityQuery) use ($like) {
                            $cityQuery->where('name', 'like', $like);
                        });

                    if ($normalizedLike !== $like) {
                        $nested->orWhere('first_name', 'like', $normalizedLike)
                            ->orWhere('last_name', 'like', $normalizedLike)
                            ->orWhereRaw($this->fullNameExpression() . ' LIKE ?', [$normalizedLike])
                            ->orWhere('crm_customer_id', 'like', $normalizedLike);
                    }

                    if ($numericSearch !== '') {
                        $nested->orWhereRaw($this->normalizedColumnExpression('mobile') . ' LIKE ?', ["%{$numericSearch}%"])
                            ->orWhereRaw($this->castToTextExpression('id') . ' LIKE ?', ["%{$numericSearch}%"]);
                    }
                });
            })
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('account-statements.index', compact('customers', 'q'));
    }


    private function normalizeSearchTerm(string $value): string
    {
        return strtr($value, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }

    private function fullNameExpression(): string
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return "TRIM(COALESCE(first_name, '') || ' ' || COALESCE(last_name, ''))";
        }

        return "TRIM(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, '')))";
    }


    private function castToTextExpression(string $column): string
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return "CAST({$column} AS TEXT)";
        }

        return "CAST({$column} AS CHAR)";
    }

    private function normalizedColumnExpression(string $column): string
    {
        return "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE({$column}, ' ', ''), '-', ''), '(', ''), ')', ''), '+', '')";
    }

    public function show(Customer $customer)
    {
        $ledgers = CustomerLedger::query()
            ->effectiveForBalance()
            ->where('customer_id', $customer->id)
            ->orderByDesc('created_at')
            ->paginate(25);

        $invoiceIds = $ledgers->getCollection()
            ->where('reference_type', Invoice::class)
            ->pluck('reference_id')
            ->filter()
            ->unique()
            ->values();

        $paymentIds = $ledgers->getCollection()
            ->where('reference_type', InvoicePayment::class)
            ->pluck('reference_id')
            ->filter()
            ->unique()
            ->values();

        $transferIds = $ledgers->getCollection()
            ->where('reference_type', WarehouseTransfer::class)
            ->pluck('reference_id')
            ->filter()
            ->unique()
            ->values();

        $purchaseIds = $ledgers->getCollection()
            ->where('reference_type', Purchase::class)
            ->pluck('reference_id')
            ->filter()
            ->unique()
            ->values();

        $legacyReturnInvoiceNumbersByLedger = $ledgers->getCollection()
            ->filter(fn (CustomerLedger $ledger) => (blank($ledger->reference_type) || blank($ledger->reference_id)) && str_contains((string) $ledger->note, 'برگشت از فروش'))
            ->mapWithKeys(function (CustomerLedger $ledger) {
                if (! preg_match('/شماره\s+([^\s\-|]+)/u', (string) $ledger->note, $matches)) {
                    return [];
                }

                return [$ledger->id => $this->normalizeSearchTerm($matches[1])];
            })
            ->filter()
            ->unique();

        $payments = InvoicePayment::query()
            ->with([
                'cheque',
                'creator:id,name',
                'invoice:id,uuid,total,customer_name',
            ])
            ->whereIn('id', $paymentIds)
            ->get(['id', 'invoice_id', 'customer_id', 'created_by', 'method', 'amount', 'paid_at', 'bank_name', 'note'])
            ->keyBy('id');

        $transfers = WarehouseTransfer::query()
            ->whereIn('id', $transferIds)
            ->get(['id', 'reference', 'voucher_type', 'external_invoice_number', 'total_amount'])
            ->keyBy('id');

        $legacyReturnInvoiceNumbers = $legacyReturnInvoiceNumbersByLedger->values();

        $legacyReturnTransfersByInvoiceNumber = WarehouseTransfer::query()
            ->where('voucher_type', WarehouseTransfer::TYPE_CUSTOMER_RETURN)
            ->latest('id')
            ->get(['id', 'reference', 'voucher_type', 'customer_id', 'external_invoice_number', 'total_amount', 'note'])
            ->filter(fn (WarehouseTransfer $transfer) => $legacyReturnInvoiceNumbers->contains($this->legacyReturnMatchKey($transfer, $legacyReturnInvoiceNumbers)))
            ->sortByDesc(fn (WarehouseTransfer $transfer) => (int) $transfer->customer_id === (int) $customer->id)
            ->unique(fn (WarehouseTransfer $transfer) => $this->legacyReturnMatchKey($transfer, $legacyReturnInvoiceNumbers))
            ->keyBy(fn (WarehouseTransfer $transfer) => $this->legacyReturnMatchKey($transfer, $legacyReturnInvoiceNumbers));

        $legacyReturnTransfers = $legacyReturnInvoiceNumbersByLedger
            ->mapWithKeys(fn (string $invoiceNumber, int $ledgerId) => [
                $ledgerId => $legacyReturnTransfersByInvoiceNumber->get($invoiceNumber),
            ])
            ->filter();

        $purchases = Purchase::query()
            ->with('supplier:id,name,phone')
            ->whereIn('id', $purchaseIds)
            ->get(['id', 'supplier_id', 'total_amount', 'purchased_at'])
            ->keyBy('id');

        $relatedInvoiceIds = $invoiceIds
            ->merge($payments->pluck('invoice_id')->filter()->unique()->values())
            ->unique()
            ->values();

        $invoices = Invoice::query()
            ->whereIn('id', $relatedInvoiceIds)
            ->get(['id', 'uuid', 'total'])
            ->keyBy('id');

        $totalDebit = (int) CustomerLedger::query()
            ->effectiveForBalance()
            ->where('customer_id', $customer->id)
            ->where('type', 'debit')
            ->sum('amount');

        $totalCredit = (int) CustomerLedger::query()
            ->effectiveForBalance()
            ->where('customer_id', $customer->id)
            ->where('type', 'credit')
            ->sum('amount');

        $netBalance = (int) $customer->opening_balance + $totalDebit - $totalCredit;

        $customerInvoices = Invoice::query()
            ->where('customer_id', $customer->id)
            ->orderByDesc('id')
            ->get(['id', 'uuid', 'total']);

        return view('account-statements.show', compact(
            'customer',
            'ledgers',
            'invoices',
            'payments',
            'transfers',
            'legacyReturnTransfers',
            'purchases',
            'netBalance',
            'customerInvoices'
        ));
    }


    private function legacyReturnMatchKey(WarehouseTransfer $transfer, $invoiceNumbers): ?string
    {
        $candidates = [
            $transfer->external_invoice_number,
            $transfer->reference,
            $transfer->note,
        ];

        foreach ($candidates as $candidate) {
            $normalized = $this->normalizeSearchTerm((string) $candidate);

            foreach ($invoiceNumbers as $invoiceNumber) {
                if ($invoiceNumber !== '' && str_contains($normalized, (string) $invoiceNumber)) {
                    return (string) $invoiceNumber;
                }
            }
        }

        return null;
    }

    public function storeManualAdjustment(Request $request, Customer $customer)
    {
        $request->merge([
            'amount' => $this->normalizeIntegerAmount($request->input('amount')),
        ]);

        $data = $request->validateWithBag('manualAdjustment', [
            'balance_type' => ['required', 'in:debit,credit'],
            'amount' => ['required', 'integer', 'min:1'],
            'note' => ['nullable', 'string', 'max:1000'],
        ]);

        $amount = (int) $data['amount'];
        $targetBalance = $data['balance_type'] === 'debit' ? $amount : -$amount;
        $balanceLabel = $data['balance_type'] === 'debit' ? 'بدهکار' : 'بستانکار';
        $user = $request->user();
        $userName = $user?->name ?: 'کاربر نامشخص';
        $userNote = trim((string) ($data['note'] ?? ''));
        $ledgerNote = 'تنظیم دستی مانده حساب به ' . $balanceLabel . ' ' . number_format($amount) . ' ریال';

        if ($userNote !== '') {
            $ledgerNote .= ' - ' . $userNote;
        }

        $createdLedger = DB::transaction(function () use ($customer, $data, $amount, $targetBalance, $ledgerNote, $user, $userName, $userNote) {
            $totalDebit = (int) CustomerLedger::query()
                ->effectiveForBalance()
                ->where('customer_id', $customer->id)
                ->where('type', 'debit')
                ->sum('amount');

            $totalCredit = (int) CustomerLedger::query()
                ->effectiveForBalance()
                ->where('customer_id', $customer->id)
                ->where('type', 'credit')
                ->sum('amount');

            $currentBalance = (int) $customer->opening_balance + $totalDebit - $totalCredit;
            $delta = $targetBalance - $currentBalance;

            if ($delta === 0) {
                return null;
            }

            $ledger = CustomerLedger::create([
                'customer_id' => $customer->id,
                'type' => $delta > 0 ? 'debit' : 'credit',
                'amount' => abs($delta),
                'reference_type' => null,
                'reference_id' => null,
                'note' => $ledgerNote,
            ]);

            if (Schema::hasTable('activity_logs')) {
                ActivityLog::create([
                    'user_id' => $user?->id,
                    'action' => 'account_statement_adjust',
                    'subject_type' => CustomerLedger::class,
                    'subject_id' => $ledger->id,
                    'description' => 'اصلاح دستی گردش حساب مشتری توسط ' . $userName,
                    'properties' => [
                        'customer_id' => $customer->id,
                        'amount' => $amount,
                        'balance_type' => $data['balance_type'],
                        'target_balance' => $targetBalance,
                        'previous_balance' => $currentBalance,
                        'delta' => $delta,
                        'ledger_type' => $ledger->type,
                        'ledger_amount' => (int) $ledger->amount,
                        'user_id' => $user?->id,
                        'note' => $userNote === '' ? null : $userNote,
                    ],
                    'occurred_at' => now(),
                ]);
            }

            return $ledger;
        });

        if ($createdLedger === null) {
            return redirect()
                ->route('account-statements.show', $customer)
                ->with('success', 'مانده حساب همین الان با مبلغ واردشده برابر است.');
        }

        return redirect()
            ->route('account-statements.show', $customer)
            ->with('success', 'مانده حساب با موفقیت روی مبلغ واردشده تنظیم شد.');
    }

    private function normalizeIntegerAmount(mixed $value): string
    {
        $normalized = strtr((string) $value, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);

        return preg_replace('/[^0-9]/', '', $normalized) ?? '';
    }

    public function showInvoice(string $uuid)
    {
        $invoice = Invoice::query()
            ->with(['items.product', 'items.variant'])
            ->where('uuid', $uuid)
            ->firstOrFail();

        return view('account-statements.documents.invoice-view', compact('invoice'));
    }

    public function showReturnFromSale(WarehouseTransfer $voucher)
    {
        abort_unless($voucher->voucher_type === WarehouseTransfer::TYPE_CUSTOMER_RETURN, 404);

        $voucher->load([
            'items.product',
            'items.variant.modelList',
            'items.variant.color',
            'items.destinationWarehouse',
            'fromWarehouse',
            'toWarehouse',
            'relatedInvoice',
            'customer',
            'user',
        ]);

        return view('account-statements.documents.return-from-sale-view', compact('voucher'));
    }

    public function showPayment(InvoicePayment $payment)
    {
        $payment->load([
            'cheque',
            'invoice:id,uuid,customer_name,customer_mobile,total',
        ]);

        return view('account-statements.documents.payment-view', compact('payment'));
    }
}
