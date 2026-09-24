<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\SalesReturnDocument;
use App\Models\WarehouseTransfer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccountStatementController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        $normalizedSearch = $this->normalizeSearchTerm($q);
        $numericSearch = preg_replace('/\D+/', '', $normalizedSearch);

        $customers = Customer::query()
            ->with('cityRelation:id,name')
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
                        ->orWhereHas('cityRelation', function ($cityQuery) use ($like) {
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
            ->where('customer_id', $customer->id)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25);

        $invoiceIds = $ledgers->getCollection()->where('reference_type', Invoice::class)->pluck('reference_id')->filter()->unique()->values();
        $paymentIds = $ledgers->getCollection()->where('reference_type', InvoicePayment::class)->pluck('reference_id')->filter()->unique()->values();
        $transferIds = $ledgers->getCollection()->where('reference_type', WarehouseTransfer::class)->pluck('reference_id')->filter()->unique()->values();
        $salesReturnIds = $ledgers->getCollection()->where('reference_type', SalesReturnDocument::class)->pluck('reference_id')->filter()->unique()->values();

        $payments = InvoicePayment::query()
            ->with(['cheque', 'creator:id,name', 'invoice:id,uuid,total,customer_name'])
            ->whereIn('id', $paymentIds)
            ->get([
                'id',
                'invoice_id',
                'customer_id',
                'created_by',
                'method',
                'amount',
                'paid_at',
                'bank_name',
                'payment_identifier',
                'note',
            ])
            ->keyBy('id');

        $transfers = WarehouseTransfer::query()
            ->whereIn('id', $transferIds)
            ->get(['id', 'reference', 'voucher_type'])
            ->keyBy('id');

        $salesReturnDocuments = SalesReturnDocument::query()
            ->whereIn('id', $salesReturnIds)
            ->get(['id', 'document_number', 'source_type', 'total_refund_amount'])
            ->keyBy('id');

        $relatedInvoiceIds = $invoiceIds
            ->merge($payments->pluck('invoice_id')->filter()->unique()->values())
            ->unique()
            ->values();

        $invoices = Invoice::query()
            ->whereIn('id', $relatedInvoiceIds)
            ->get([
                'id',
                'uuid',
                'subtotal',
                'shipping_price',
                'discount_amount',
                'product_discount_amount',
                'invoice_discount_amount',
                'total',
                'external_order_id',
                'status',
            ])
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
        $balanceStatus = $netBalance > 0 ? 'debtor' : ($netBalance < 0 ? 'creditor' : 'settled');
        $balanceStatusLabel = match ($balanceStatus) {
            'debtor' => 'بدهکار',
            'creditor' => 'بستانکار',
            default => 'تسویه',
        };
        $balanceAmount = abs($netBalance);

        /*
         * Invoice settlement view:
         * Keep ledger accounting untouched, but expose the financial story of each
         * invoice (gross amount, discounts, warehouse revisions, payments, remaining
         * debt / overpayment) in a human-readable way.
         */
        $customerInvoices = Invoice::query()
            ->where('customer_id', $customer->id)
            ->orderByDesc('id')
            ->get([
                'id',
                'uuid',
                'external_order_id',
                'document_date',
                'created_at',
                'subtotal',
                'shipping_price',
                'discount_amount',
                'product_discount_amount',
                'invoice_discount_amount',
                'discount_breakdown',
                'total',
                'status',
                'items_updated_at',
            ]);

        $customerInvoiceIds = $customerInvoices->pluck('id');

        $allInvoicePayments = InvoicePayment::query()
            ->with(['cheque', 'creator:id,name'])
            ->whereIn('invoice_id', $customerInvoiceIds)
            ->orderBy('paid_at')
            ->orderBy('id')
            ->get([
                'id',
                'invoice_id',
                'customer_id',
                'created_by',
                'method',
                'amount',
                'paid_at',
                'bank_name',
                'payment_identifier',
                'note',
                'created_at',
            ])
            ->groupBy('invoice_id');

        $revisionsByInvoice = collect();

        if (
            $customerInvoiceIds->isNotEmpty()
            && DB::getSchemaBuilder()->hasTable('invoice_collection_revisions')
        ) {
            $revisionsByInvoice = DB::table('invoice_collection_revisions')
                ->whereIn('invoice_id', $customerInvoiceIds)
                ->orderBy('invoice_id')
                ->orderBy('revision_number')
                ->get([
                    'id',
                    'invoice_id',
                    'revision_number',
                    'old_total',
                    'new_total',
                    'reason_type',
                    'reason_note',
                    'changed_by',
                    'created_at',
                ])
                ->groupBy('invoice_id');
        }

        $invoiceSummaries = $customerInvoices
            ->take(30)
            ->map(function (Invoice $invoice) use ($allInvoicePayments, $revisionsByInvoice) {
                $invoicePayments = $allInvoicePayments->get($invoice->id, collect());
                $revisions = $revisionsByInvoice->get($invoice->id, collect());

                $paidTotal = (int) $invoicePayments->sum('amount');
                $finalTotal = (int) $invoice->total;
                $remaining = max($finalTotal - $paidTotal, 0);
                $overpayment = max($paidTotal - $finalTotal, 0);

                $subtotal = (int) $invoice->subtotal;
                $shipping = (int) $invoice->shipping_price;
                $productDiscount = (int) $invoice->product_discount_amount;
                $invoiceDiscount = (int) $invoice->invoice_discount_amount;
                $totalDiscount = (int) $invoice->discount_amount;

                // Legacy rows may only have discount_amount populated.
                if ($productDiscount === 0 && $invoiceDiscount === 0 && $totalDiscount > 0) {
                    $invoiceDiscount = $totalDiscount;
                }

                return [
                    'invoice' => $invoice,
                    'subtotal' => $subtotal,
                    'shipping' => $shipping,
                    'gross_before_discount' => $subtotal + $shipping,
                    'product_discount' => $productDiscount,
                    'invoice_discount' => $invoiceDiscount,
                    'total_discount' => $totalDiscount,
                    'final_total' => $finalTotal,
                    'paid_total' => $paidTotal,
                    'remaining' => $remaining,
                    'overpayment' => $overpayment,
                    'payments' => $invoicePayments,
                    'revisions' => $revisions,
                    'has_adjustment' => $revisions->isNotEmpty(),
                    'first_total_before_adjustment' => $revisions->isNotEmpty()
                        ? (int) $revisions->first()->old_total
                        : null,
                    'last_total_after_adjustment' => $revisions->isNotEmpty()
                        ? (int) $revisions->last()->new_total
                        : null,
                ];
            });

        $statementTotals = [
            'opening_balance' => (int) $customer->opening_balance,
            'total_debit' => $totalDebit,
            'total_credit' => $totalCredit,
            'invoice_final_total' => (int) $customerInvoices->sum('total'),
            'invoice_discount_total' => (int) $customerInvoices->sum('discount_amount'),
            'invoice_payment_total' => (int) $allInvoicePayments->flatten(1)->sum('amount'),
        ];

        return view('account-statements.show', compact(
            'customer',
            'ledgers',
            'invoices',
            'payments',
            'transfers',
            'salesReturnDocuments',
            'netBalance',
            'balanceStatus',
            'balanceStatusLabel',
            'balanceAmount',
            'totalDebit',
            'totalCredit',
            'customerInvoices',
            'invoiceSummaries',
            'statementTotals'
        ));
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
