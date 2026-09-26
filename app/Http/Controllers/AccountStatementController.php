<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\SalesReturnDocument;
use App\Models\WarehouseTransfer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AccountStatementController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        $normalizedSearch = $this->normalizeSearchTerm($q);
        $normalizedNameSearch = strtr($normalizedSearch, ["\u{064A}" => "\u{06CC}", "\u{0643}" => "\u{06A9}"]);
        $numericSearch = preg_replace('/\D+/', '', $normalizedSearch);
        $sort = in_array($request->query('sort'), ['name', 'mobile', 'debt', 'credit', 'status'], true)
            ? $request->query('sort') : null;
        $direction = in_array($request->query('direction'), ['asc', 'desc'], true)
            ? $request->query('direction') : 'desc';

        $query = Customer::query()
            ->with('cityRelation:id,name')
            ->withBalance()
            ->when($q !== '', function ($query) use ($q, $normalizedSearch, $normalizedNameSearch, $numericSearch) {
                $like = "%{$q}%";
                $normalizedLike = "%{$normalizedSearch}%";
                $nameLike = "%{$normalizedNameSearch}%";

                $query->where(function ($nested) use ($like, $normalizedLike, $nameLike, $numericSearch) {
                    $nested->where('first_name', 'like', $like)
                        ->orWhere('last_name', 'like', $like)
                        ->orWhere('name', 'like', $like)
                        ->orWhereRaw($this->fullNameExpression() . ' LIKE ?', [$like])
                        ->orWhere('mobile', 'like', $like)
                        ->orWhere('crm_customer_id', 'like', $like)
                        ->orWhereHas('cityRelation', function ($cityQuery) use ($like) {
                            $cityQuery->where('name', 'like', $like);
                        });

                    if ($normalizedLike !== $like) {
                        $nested->orWhere('first_name', 'like', $normalizedLike)
                            ->orWhere('last_name', 'like', $normalizedLike)
                            ->orWhere('name', 'like', $normalizedLike)
                            ->orWhereRaw($this->fullNameExpression() . ' LIKE ?', [$normalizedLike])
                            ->orWhere('crm_customer_id', 'like', $normalizedLike);
                    }

                    $nested->orWhereRaw($this->normalizedNameExpression('first_name') . ' LIKE ?', [$nameLike])
                        ->orWhereRaw($this->normalizedNameExpression('last_name') . ' LIKE ?', [$nameLike])
                        ->orWhereRaw($this->normalizedNameExpression('name') . ' LIKE ?', [$nameLike])
                        ->orWhereRaw($this->normalizedNameExpression($this->fullNameExpression()) . ' LIKE ?', [$nameLike]);

                    if ($numericSearch !== '') {
                        $nested->orWhereRaw($this->normalizedColumnExpression('mobile') . ' LIKE ?', ["%{$numericSearch}%"])
                            ->orWhereRaw($this->castToTextExpression('id') . ' LIKE ?', ["%{$numericSearch}%"]);
                    }
                });
            });

        if ($sort === 'name') {
            $query->orderByRaw("COALESCE(NULLIF(name, ''), {$this->fullNameExpression()}) {$direction}");
        } elseif ($sort === 'mobile') {
            $query->orderBy('mobile', $direction);
        } elseif (in_array($sort, ['debt', 'credit', 'status'], true)) {
            $balance = "COALESCE(customers.opening_balance, 0) + COALESCE(SUM(CASE WHEN customer_ledgers.type = 'debit' THEN customer_ledgers.amount WHEN customer_ledgers.type = 'credit' THEN -customer_ledgers.amount ELSE 0 END), 0)";
            $sortValue = match ($sort) {
                'debt' => "CASE WHEN {$balance} > 0 THEN {$balance} ELSE 0 END",
                'credit' => "CASE WHEN {$balance} < 0 THEN -({$balance}) ELSE 0 END",
                default => $balance,
            };
            $query->addSelect(['sort_value' => CustomerLedger::query()
                ->effectiveForBalance()
                ->whereColumn('customer_ledgers.customer_id', 'customers.id')
                ->selectRaw($sortValue)])
                ->orderBy('sort_value', $direction);
        }

        $customers = $query->orderByDesc('customers.id')
            ->paginate(20)
            ->withQueryString();

        return view('account-statements.index', compact('customers', 'q', 'sort', 'direction'));
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

    private function normalizedNameExpression(string $expression): string
    {
        return "REPLACE(REPLACE({$expression}, 'ي', 'ی'), 'ك', 'ک')";
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
            ->where('customer_id', $customer->id)
            ->orderByDesc('created_at')
            ->paginate(25);
        $effectiveLedgerIds = CustomerLedger::query()
            ->effectiveForBalance()
            ->whereIn('id', $ledgers->getCollection()->pluck('id'))
            ->pluck('id')
            ->mapWithKeys(fn ($id) => [$id => true]);

        $invoiceIds = $ledgers->getCollection()->where('reference_type', Invoice::class)->pluck('reference_id')->filter()->unique()->values();
        $paymentIds = $ledgers->getCollection()->where('reference_type', InvoicePayment::class)->pluck('reference_id')->filter()->unique()->values();
        $transferIds = $ledgers->getCollection()->where('reference_type', WarehouseTransfer::class)->pluck('reference_id')->filter()->unique()->values();
        $salesReturnIds = $ledgers->getCollection()->where('reference_type', SalesReturnDocument::class)->pluck('reference_id')->filter()->unique()->values();

        $payments = InvoicePayment::query()
            ->with(['cheque', 'creator:id,name', 'invoice:id,uuid,total,customer_name'])
            ->whereIn('id', $paymentIds)
            ->get(['id', 'invoice_id', 'customer_id', 'created_by', 'method', 'amount', 'paid_at', 'bank_name', 'note'])
            ->keyBy('id');

        $transfers = WarehouseTransfer::query()
            ->whereIn('id', $transferIds)
            ->get(['id', 'reference', 'voucher_type'])
            ->keyBy('id');

        $salesReturnDocuments = SalesReturnDocument::query()
            ->whereIn('id', $salesReturnIds)
            ->get(['id', 'document_number', 'source_type', 'total_refund_amount'])
            ->keyBy('id');

        $relatedInvoiceIds = $invoiceIds->merge($payments->pluck('invoice_id')->filter()->unique()->values())->unique()->values();

        $invoices = Invoice::query()
            ->whereIn('id', $relatedInvoiceIds)
            ->get(['id', 'uuid', 'total'])
            ->keyBy('id');

        $totalDebit = (int) CustomerLedger::query()->effectiveForBalance()->where('customer_id', $customer->id)->where('type', 'debit')->sum('amount');
        $totalCredit = (int) CustomerLedger::query()->effectiveForBalance()->where('customer_id', $customer->id)->where('type', 'credit')->sum('amount');
        $netBalance = (int) $customer->opening_balance + $totalDebit - $totalCredit;
        $adjustments = ActivityLog::query()
            ->with('user:id,name')
            ->where('subject_type', Customer::class)
            ->where('subject_id', $customer->id)
            ->where('action', 'customer_balance_adjusted')
            ->orderByDesc('id')
            ->paginate(10, ['*'], 'adjustments_page');

        $customerInvoices = Invoice::query()->where('customer_id', $customer->id)->orderByDesc('id')->get(['id', 'uuid', 'total']);

        return view('account-statements.show', compact(
            'customer',
            'ledgers',
            'effectiveLedgerIds',
            'invoices',
            'payments',
            'transfers',
            'salesReturnDocuments',
            'netBalance',
            'totalDebit',
            'totalCredit',
            'adjustments',
            'customerInvoices'
        ));
    }

    public function storeAdjustment(Customer $customer, Request $request)
    {
        $data = $request->validate([
            'balance_type' => ['required', 'in:debit,credit,settled'],
            'target_amount' => ['required', 'string', 'max:32'],
            'expected_balance' => ['required', 'integer'],
            'reason' => ['required', 'string', 'min:10', 'max:1000'],
        ]);

        $amount = strtr(trim($data['target_amount']), [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
        $amount = preg_replace('/[\s,٬،]+/u', '', $amount);
        if (! preg_match('/^\d{1,16}$/', $amount) || (int) $amount > 1_000_000_000_000_000) {
            throw ValidationException::withMessages(['target_amount' => 'مبلغ مانده باید یک عدد معتبر به ریال باشد.']);
        }
        $amount = (int) $amount;
        if (($data['balance_type'] === 'settled' && $amount !== 0) || ($data['balance_type'] !== 'settled' && $amount === 0)) {
            throw ValidationException::withMessages(['target_amount' => 'برای وضعیت تسویه مبلغ صفر و برای بدهکار یا بستانکار مبلغ بیشتر از صفر وارد کنید.']);
        }
        $targetBalance = match ($data['balance_type']) {
            'debit' => $amount,
            'credit' => -$amount,
            default => 0,
        };

        DB::transaction(function () use ($customer, $data, $targetBalance) {
            $lockedCustomer = Customer::query()->whereKey($customer->id)->lockForUpdate()->firstOrFail();
            $before = $this->balanceForCustomer($lockedCustomer);
            if ($before !== (int) $data['expected_balance']) {
                throw ValidationException::withMessages(['target_amount' => 'مانده حساب از زمان باز شدن صفحه تغییر کرده است؛ صفحه را تازه‌سازی و دوباره بررسی کنید.']);
            }
            $difference = $targetBalance - $before;
            if ($difference === 0) {
                throw ValidationException::withMessages(['target_amount' => 'مانده جدید با مانده فعلی یکسان است.']);
            }

            $log = ActivityLog::query()->create([
                'user_id' => auth()->id(),
                'action' => 'customer_balance_adjusted',
                'subject_type' => Customer::class,
                'subject_id' => $lockedCustomer->id,
                'description' => 'مانده حساب مشتری با سند اصلاحی تنظیم شد.',
                'properties' => [
                    'balance_before' => $before,
                    'balance_after' => $targetBalance,
                    'difference' => $difference,
                    'reason' => trim($data['reason']),
                ],
                'occurred_at' => now(),
            ]);
            $ledger = CustomerLedger::query()->create([
                'customer_id' => $lockedCustomer->id,
                'type' => $difference > 0 ? 'debit' : 'credit',
                'amount' => abs($difference),
                'reference_type' => ActivityLog::class,
                'reference_id' => $log->id,
                'note' => 'سند اصلاح مانده حساب #' . $log->id,
            ]);
            $log->update(['properties' => array_merge($log->properties, ['ledger_id' => $ledger->id])]);
        }, 3);

        return redirect()->route('account-statements.show', $customer)->with('success', 'مانده حساب با سند اصلاحی ثبت شد.');
    }

    private function balanceForCustomer(Customer $customer): int
    {
        $debit = CustomerLedger::query()->effectiveForBalance()->where('customer_id', $customer->id)->where('type', 'debit')->sum('amount');
        $credit = CustomerLedger::query()->effectiveForBalance()->where('customer_id', $customer->id)->where('type', 'credit')->sum('amount');

        return (int) $customer->opening_balance + (int) $debit - (int) $credit;
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
