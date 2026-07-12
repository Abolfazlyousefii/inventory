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
            ->where('customer_id', $customer->id)
            ->orderByDesc('created_at')
            ->paginate(25);

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

        $totalDebit = (int) CustomerLedger::query()->where('customer_id', $customer->id)->where('type', 'debit')->sum('amount');
        $totalCredit = (int) CustomerLedger::query()->where('customer_id', $customer->id)->where('type', 'credit')->sum('amount');
        $netBalance = (int) $customer->opening_balance + $totalDebit - $totalCredit;

        $customerInvoices = Invoice::query()->where('customer_id', $customer->id)->orderByDesc('id')->get(['id', 'uuid', 'total']);

        return view('account-statements.show', compact(
            'customer',
            'ledgers',
            'invoices',
            'payments',
            'transfers',
            'salesReturnDocuments',
            'netBalance',
            'customerInvoices'
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
