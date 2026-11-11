<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\InvoicePayment;
use App\Models\PersonnelAccountDocument;
use App\Models\Purchase;
use App\Models\PurchaseReturn;
use App\Models\SalesReturnDocument;
use App\Models\WarehouseTransfer;
use App\Services\PersonAccountStatementService;
use App\Support\PageAccessCatalog;

class PersonAccountStatementController extends Controller
{
    public function __construct(private readonly PersonAccountStatementService $statementService)
    {
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

            if ($ledger->reference_type === Purchase::class && $ledger->reference_id) {
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
