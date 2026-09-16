<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\SalesReturnDocument;
use App\Models\WarehouseTransfer;
use Bavix\Wallet\Models\Transaction;
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
        /*
         * گرفتن Default Wallet مشتری
         */
        $wallet = $customer->wallet;

        /*
         * گرفتن تراکنش‌های واقعی Laravel Wallet
         *
         * دیگر هیچ اطلاعاتی از customer_ledgers
         * برای نمایش گردش حساب خوانده نمی‌شود.
         */
        $transactions = Transaction::query()
            ->where('wallet_id', $wallet->id)
            ->where('confirmed', true)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(25)
            ->withQueryString();

        /*
         * موجودی نهایی مستقیماً از Laravel Wallet
         *
         * مثبت  => مشتری بدهکار
         * منفی  => مشتری بستانکار
         * صفر   => تسویه
         */
        $netBalance = (int) $customer->balanceInt;

        /*
         * این قسمت برای فرم «افزودن پرداخت» داخل Blade
         * همچنان لازم است.
         */
        $customerInvoices = Invoice::query()
            ->where('customer_id', $customer->id)
            ->orderByDesc('id')
            ->get([
                'id',
                'uuid',
                'total',
            ]);

        return view('account-statements.show', compact(
            'customer',
            'transactions',
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
