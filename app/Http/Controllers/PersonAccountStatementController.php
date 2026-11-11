<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\PersonnelAccountDocument;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\SupplierAccountTransaction;
use App\Models\SupplierAccountTransaction;
use App\Models\SupplierLedger;
use App\Support\ActivityLogger;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use App\Models\SalesReturnDocument;
use App\Models\WarehouseTransfer;
use App\Services\PersonAccountStatementService;
use App\Support\PageAccessCatalog;

class PersonAccountStatementController extends Controller
{
    public function __construct(private readonly PersonAccountStatementService $statementService)
    {
    }

    public function storeSupplierTransaction(Request $request, string $personKey)
    {
        $person = $this->statementService->resolve($personKey);
        $supplier = $person['supplier'];
        abort_if(! $supplier, 404);

        $data = $request->validate([
            'direction' => ['required', Rule::in(['payment', 'receipt'])],
            'amount' => ['required', 'integer', 'min:1', 'max:9000000000000000'],
            'transaction_date' => ['required', 'date'],
            'method' => ['nullable', 'string', 'max:50'],
            'reference' => ['nullable', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:255'],
            'submission_token' => ['nullable', 'uuid'],
        ]);

        $token = (string) ($data['submission_token'] ?? Str::uuid());
        $transaction = DB::transaction(function () use ($supplier, $data, $token) {
            $existing = SupplierAccountTransaction::query()
                ->where('submission_token', $token)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                if ((int) $existing->supplier_id !== (int) $supplier->id) {
                    abort(422, 'شناسه ثبت قبلاً برای شخص دیگری استفاده شده است.');
                }

                return $existing;
            }

            $note = trim((string) ($data['note'] ?? '')) ?: (
                $data['direction'] === 'payment' ? 'پرداخت به تأمین‌کننده' : 'دریافت از تأمین‌کننده'
            );

            $transaction = SupplierAccountTransaction::query()->create([
                'supplier_id' => (int) $supplier->id,
                'direction' => $data['direction'],
                'amount' => (int) $data['amount'],
                'transaction_date' => $data['transaction_date'],
                'method' => $data['method'] ?? null,
                'reference' => $data['reference'] ?? null,
                'note' => $note,
                'submission_token' => $token,
                'created_by' => auth()->id(),
            ]);

            SupplierLedger::query()->create([
                'supplier_id' => (int) $supplier->id,
                'type' => $data['direction'] === 'payment' ? 'debit' : 'credit',
                'amount' => (int) $data['amount'],
                'reference_type' => SupplierAccountTransaction::class,
                'reference_id' => (int) $transaction->id,
                'note' => $note,
            ]);

            ActivityLogger::log(
                $data['direction'] === 'payment' ? 'supplier_payment_recorded' : 'supplier_receipt_recorded',
                $transaction,
                $data['direction'] === 'payment'
                    ? 'پرداخت به تأمین‌کننده در گردش حساب ثبت شد.'
                    : 'دریافت از تأمین‌کننده در گردش حساب ثبت شد.',
                [
                    'supplier_id' => (int) $supplier->id,
                    'amount' => (int) $data['amount'],
                    'direction' => $data['direction'],
                ],
            );

            return $transaction;
        }, 3);

        return redirect()
            ->route('persons.account', $personKey)
            ->with('success', 'تراکنش با موفقیت در گردش حساب ثبت شد.');
    }

    public function show(string $personKey)
    {
        $statement = $this->statementService->statement($personKey);
        $sources = [];

        foreach ($statement['ledgers'] as $ledger) {
            $key = $ledger->ledger_key;
            $label = 'GL-'.$ledger->id;
            $description = $ledger->note ?: ($ledger->type === 'debit' ? 'ثبت بدهکار' : 'ثبت بستانکار');
            $url = null;

            if ($ledger->reference_type === SupplierAccountTransaction::class && $ledger->reference_id) {
                $transaction = SupplierAccountTransaction::query()->find($ledger->reference_id);
                $label = ($transaction?->direction === 'receipt' ? 'REC-' : 'PAY-').$ledger->reference_id;
                $description = $transaction?->direction === 'receipt' ? 'دریافت از تأمین‌کننده' : 'پرداخت به تأمین‌کننده';
            } elseif ($ledger->reference_type === Purchase::class && $ledger->reference_id) {
                $purchase = Purchase::query()->find($ledger->reference_id);
                $label = 'PUR-'.$ledger->reference_id;
                $description = 'خرید کالا';
                if ($purchase && PageAccessCatalog::userCanRoute(auth()->user(), 'purchases.show')) {
                    $url = route('purchases.show', $purchase->id);
                }
            } elseif ($ledger->reference_type === PurchaseReturn::class && $ledger->reference_id) {
                $return = PurchaseReturn::withTrashed()->find($ledger->reference_id);
                $label = $return ? 'PR-'.$return->document_number : 'PR-'.$ledger->reference_id;
                $description = $return?->trashed() ? 'برگشت از خرید حذف‌شده' : 'برگشت از خرید';
                if ($return && PageAccessCatalog::userCanRoute(auth()->user(), 'purchase-returns.show')) {
                    $url = route('purchase-returns.show', $return->id);
                }
            } elseif ($ledger->reference_type === Invoice::class && $ledger->reference_id) {
                $invoice = Invoice::query()->find($ledger->reference_id);
                $label = $invoice?->uuid ?: 'INV-'.$ledger->reference_id;
                $description = 'فاکتور فروش';
                if ($invoice && PageAccessCatalog::userCanRoute(auth()->user(), 'account-statements.documents.invoices.show')) {
                    $url = route('account-statements.documents.invoices.show', $invoice->uuid);
                }
            } elseif ($ledger->reference_type === InvoicePayment::class && $ledger->reference_id) {
                $payment = InvoicePayment::query()->find($ledger->reference_id);
                $label = 'PAY-'.$ledger->reference_id;
                $description = 'دریافت وجه';
                if ($payment && PageAccessCatalog::userCanRoute(auth()->user(), 'account-statements.documents.payments.show')) {
                    $url = route('account-statements.documents.payments.show', $payment->id);
                }
            } elseif ($ledger->reference_type === SalesReturnDocument::class && $ledger->reference_id) {
                $return = SalesReturnDocument::query()->find($ledger->reference_id);
                $label = $return?->document_number ?: 'SR-'.$ledger->reference_id;
                $description = 'برگشت از فروش';
                if ($return && PageAccessCatalog::userCanRoute(auth()->user(), 'sales-returns.show')) {
                    $url = route('sales-returns.show', $return->id);
                }
            } elseif ($ledger->reference_type === WarehouseTransfer::class && $ledger->reference_id) {
                $transfer = WarehouseTransfer::query()->find($ledger->reference_id);
                $label = $transfer?->reference ?: 'TR-'.$ledger->reference_id;
                $description = 'سند انبار';
            } elseif ($ledger->reference_type === PersonnelAccountDocument::class && $ledger->reference_id) {
                $document = PersonnelAccountDocument::query()->find($ledger->reference_id);
                $label = 'PER-'.$ledger->reference_id;
                $description = $document?->description ?: 'سند پرسنلی';
            } elseif ($ledger->reference_type === ActivityLog::class && $ledger->reference_id) {
                $label = 'GL-'.$ledger->id;
            }

            $sources[$key] = compact('label', 'description', 'url');
        }

        return view('persons.account', $statement + ['sources' => $sources]);
    }
}
