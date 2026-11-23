<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\PreinvoiceOrder;
use App\Models\SalesReturnDocument;
use App\Models\User;
use App\Services\CustomerOwnership;
use App\Services\CustomerWorkspace;
use Illuminate\Http\Request;

class CommercialCustomerWorkspaceController extends Controller
{
    public function __construct(private readonly CustomerWorkspace $workspace, private readonly CustomerOwnership $ownership) {}

    private function view(Request $request, string $view, array $data)
    {
        return response()->view($view, $data)->header('Cache-Control', 'private, no-store');
    }

    public function index(Request $request)
    {
        $this->workspace->authorize($request->user());
        $filters = $request->validate(['q' => 'nullable|string|max:100', 'status' => 'nullable|in:active,inactive,all', 'balance' => 'nullable|in:all,debt,credit,settled', 'seller_id' => 'nullable|integer|min:0', 'sort' => 'nullable|in:recent,name,balance', 'page' => 'nullable|integer|min:1']);
        $customers = $this->workspace->listing($request->user(), $filters)->paginate(12)->withQueryString();
        $broad = $this->ownership->canSellAny($request->user());
        $sellers = $broad ? User::query()->activeErpUsers()->orderBy('name')->get(['id', 'name']) : collect();

        return $this->view($request, $request->ajax() ? 'commercial.customer-workspace.list' : 'commercial.customer-workspace.index', compact('customers', 'filters', 'broad', 'sellers'));
    }

    public function show(Request $request, int $customer)
    {
        $customer = $this->workspace->customer($request->user(), $customer);
        $data = $request->validate(['tab' => 'nullable|in:overview,invoices,preinvoices,returns,ledger,activity', 'page' => 'nullable|integer|min:1']);
        $tab = $data['tab'] ?? 'overview';
        $stats = $this->workspace->stats($customer);
        $records = null;
        $overview = [];
        $meta = [];
        $openFollowups = collect();
        if ($tab === 'activity') {
            $records = \App\Models\CustomerInteraction::where('customer_id',$customer->id)->with(['author:id,name','followup.assignee:id,name','followup.closer:id,name'])->orderByDesc('id')->paginate(15)->withQueryString();
            $openFollowups = \App\Models\CustomerFollowup::where('customer_id',$customer->id)->where('status','open')->with('assignee:id,name')->orderBy('due_at')->limit(5)->get();
        } elseif ($tab === 'overview') {
            $overview = ['invoices' => $this->workspace->invoices($customer)->limit(3)->get(), 'returns' => $this->workspace->returns($customer)->limit(2)->get(), 'ledger' => $this->workspace->ledger($customer)->limit(3)->get()];
            $meta = $this->workspace->ledgerMeta($customer, $overview['ledger']);
        } else {
            $query = match ($tab) {
                'invoices' => $this->workspace->invoices($customer),'preinvoices' => PreinvoiceOrder::where('customer_id', $customer->id)->withoutTemporaryAutosaves()->latest('created_at')->orderByDesc('id'),'returns' => $this->workspace->returns($customer),'ledger' => $this->workspace->ledger($customer)
            };
            $records = $query->paginate(15)->withQueryString();
            if ($tab === 'ledger') {
                $meta = $this->workspace->ledgerMeta($customer, $records->getCollection());
            }
        }

        return $this->view($request, $request->ajax() ? 'commercial.customer-workspace.profile-panel' : 'commercial.customer-workspace.profile', compact('customer', 'tab', 'stats', 'records', 'overview', 'meta', 'openFollowups'));
    }

    public function document(Request $request, int $customer, string $kind, int $document)
    {
        $customer = $this->workspace->customer($request->user(), $customer);
        $model = match ($kind) {
            'invoice' => Invoice::class,'preinvoice' => PreinvoiceOrder::class,'return' => SalesReturnDocument::class,default => abort(404)
        };
        $document = $model::where('customer_id', $customer->id)->findOrFail($document);
        // Bounded line pagination, no financial mutation links or purchase-cost fields.
        $lines = $document->items()->with(['product:id,name', 'variant:id,variant_name'])->paginate(30)->withQueryString();

        return $this->view($request,'commercial.customer-workspace.document',compact('customer','kind','document','lines'));
    }

    public function fullDocument(Request $request, int $customer, string $kind, int $document, string $action)
    {
        $customer = $this->workspace->customer($request->user(), $customer);
        $model = match ($kind) {
            'invoice' => Invoice::class, 'return' => SalesReturnDocument::class,
            'preinvoice' => PreinvoiceOrder::class, default => abort(404),
        };
        $document = $model::where('customer_id', $customer->id)->findOrFail($document);
        $backUrl = route('commercial.customers.show', [$customer, 'tab' => match ($kind) {'invoice' => 'invoices', 'return' => 'returns', default => 'preinvoices'}]);
        $printUrl = route('commercial.customers.document.full', [$customer, $kind, $document->id, 'print']);
        $fullUrl = route('commercial.customers.document.full', [$customer, $kind, $document->id, 'view']);
        if ($kind === 'return') {
            $document->load('customer', 'invoice', 'items.destinationWarehouse', 'items.product', 'items.variant', 'creator', 'applier');
            return $this->view($request, $action === 'print' ? 'vouchers.return-from-sale.print' : 'vouchers.return-from-sale.show', [
                'document' => $document, 'customerReadOnly' => true, 'backUrl' => $action === 'print' ? $fullUrl : $backUrl,
                'printUrl' => $printUrl,
                'canViewSourceInvoice' => $document->invoice && (int) $document->invoice->customer_id === (int) $customer->id,
                'sourceInvoiceUrl' => $document->invoice && (int) $document->invoice->customer_id === (int) $customer->id
                    ? route('commercial.customers.document.full', [$customer, 'invoice', $document->invoice->id, 'view']) : null,
                'healthCheck' => $action === 'view' ? app(\App\Services\SalesReturnReportService::class)->appliedHealthCheck($document) : [],
                'correctablePriceItemIds' => [],
            ]);
        }
        if ($action === 'print' || $kind === 'preinvoice') {
            $service = app(\App\Services\SalesPrintDocumentService::class);
            $printData = $kind === 'invoice' ? $service->invoiceData($document, 'customer') : $service->preinvoiceData($document, 'customer');
            $printData['backUrl'] = $kind === 'invoice' ? $fullUrl : $backUrl;
            $printData['customerWorkspace'] = true;
            return $this->view($request, 'prints.invoice', compact('printData'));
        }
        $invoice = $document->load('items.product', 'items.variant', 'payments.cheque', 'payments.creator', 'notes', 'seller', 'preinvoiceOrder.seller', 'preinvoiceOrder.creator');
        $paidTotal = (int) $invoice->payments->sum('amount');
        return $this->view($request, 'invoices.show', [
            'invoice' => $invoice, 'timeline' => app(\App\Services\InvoiceTimelineService::class)->entries($invoice),
            'paidTotal' => $paidTotal, 'remainingAmount' => max(0, (int) $invoice->total - $paidTotal),
            'canEditInvoice' => false, 'canRegisterPayments' => false, 'canHandleFinanceActions' => false,
            'canCancelInvoice' => false, 'canPrintInvoice' => true, 'customerReadOnly' => true,
            'statusLabels' => Invoice::statusLabels(), 'backUrl' => $backUrl, 'printUrl' => $printUrl,
        ]);
    }
}
