<?php

namespace App\Http\Controllers;

use App\Models\CommissionDocument;
use App\Models\CommissionDocumentCorrection;
use App\Models\CommissionDocumentItem;
use App\Models\CommissionLedgerEntry;
use App\Models\CommissionPeriod;
use App\Models\Invoice;
use App\Models\User;
use App\Services\Commissions\CommissionDocumentService;
use App\Services\Commissions\CommissionDocumentSnapshotService;
use App\Services\Commissions\CommissionFeatureService;
use App\Support\Currency;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class CommissionDocumentController extends Controller
{
    public function store(Request $request, CommissionDocumentService $service): RedirectResponse
    {
        $this->authorizeAction($request, 'commissions.manage_documents');
        $data = $request->validate(['seller_id' => ['required', 'integer', 'exists:users,id'], 'period_id' => ['required', 'integer', 'exists:commission_periods,id'], 'notes' => ['nullable', 'string', 'max:5000']]);
        $seller = User::query()->findOrFail($data['seller_id']);
        $period = CommissionPeriod::query()->findOrFail($data['period_id']);
        $existing = CommissionDocument::query()->where('seller_id', $seller->id)->where('commission_period_id', $period->id)->first();
        if ($existing) {
            return redirect()->route('commercial.commissions.documents.show', $existing)->with('warning', 'برای این فروشنده در این دوره قبلاً سند پورسانت ایجاد شده است.');
        }
        $document = $service->create($seller, $period, $request->user(), $data['notes'] ?? null);

        return redirect()->route('commercial.commissions.documents.show', $document)->with('success', 'سند پورسانت ایجاد و فاکتورهای واجد شرایط وارد شد.');
    }

    public function show(Request $request, CommissionDocument $document, CommissionDocumentService $service, CommissionFeatureService $features): View
    {
        $this->authorizeDocumentView($request, $document, $features);
        $document->load(['seller', 'period', 'creator']);
        $items = $document->items()->latest('invoice_date_snapshot')->paginate(30);
        $corrections = $document->corrections()->latest()->paginate(30, ['*'], 'corrections_page');
        $adjustments = $document->adjustments()->with('adjustment')->latest()->paginate(30, ['*'], 'adjustments_page');
        $events = $document->events()->with(['actor:id,name', 'item:id,invoice_number_snapshot'])->limit(100)->get();
        $editable = $document->status === CommissionDocument::STATUS_DRAFT && in_array($document->period->status, [CommissionPeriod::STATUS_OPEN, CommissionPeriod::STATUS_REVIEW], true);
        $permissions = ['manage' => $editable && $request->user()->hasPermission('commissions.manage_documents'), 'review' => $editable && $request->user()->hasPermission('commissions.review_documents'), 'print' => $request->user()->hasPermission('commissions.print_documents'),
            'review_adjustments' => $request->user()->hasPermission('commissions.review_adjustments'), 'finalize' => $request->user()->hasPermission('commissions.finalize_documents')];

        return view('commercial.commissions.documents.show', ['document' => $document, 'items' => $items, 'corrections' => $corrections, 'adjustments' => $adjustments, 'events' => $events, 'totals' => $service->totals($document), 'permissions' => $permissions]);
    }

    public function update(Request $request, CommissionDocument $document, CommissionDocumentService $service): RedirectResponse
    {
        $this->authorizeAction($request, 'commissions.manage_documents');
        $data = $request->validate(['notes' => ['nullable', 'string', 'max:5000']]);
        $service->updateNotes($document, $request->user(), $data['notes'] ?? null);

        return back()->with('success', 'یادداشت سند به‌روزرسانی شد.');
    }

    public function addInvoice(Request $request, CommissionDocument $document, CommissionDocumentService $service): RedirectResponse
    {
        $this->authorizeAction($request, 'commissions.manage_documents');
        $data = $request->validate(['invoice_id' => ['required', 'integer', 'exists:invoices,id'], 'outside_period_reason' => ['nullable', 'string', 'max:3000']]);
        $service->addInvoice($document, Invoice::query()->findOrFail($data['invoice_id']), $request->user(), $data['outside_period_reason'] ?? null);

        return back()->with('success', 'فاکتور به سند افزوده شد.');
    }

    public function remove(Request $request, CommissionDocument $document, CommissionDocumentItem $item, CommissionDocumentService $service): RedirectResponse
    {
        $this->authorizeItem($document, $item);
        $this->authorizeAction($request, 'commissions.manage_documents');
        $data = $request->validate(['reason' => ['required', 'string', 'max:3000']]);
        $service->remove($item, $request->user(), $data['reason']);

        return back()->with('success', 'فاکتور از سند خارج و رزرو آن آزاد شد.');
    }

    public function approve(Request $request, CommissionDocument $document, CommissionDocumentItem $item, CommissionDocumentService $service): RedirectResponse
    {
        $this->authorizeItem($document, $item);
        $this->authorizeAction($request, 'commissions.review_documents');
        $service->approve($item, $request->user());

        return back()->with('success', 'فاکتور تأیید شد.');
    }

    public function reject(Request $request, CommissionDocument $document, CommissionDocumentItem $item, CommissionDocumentService $service): RedirectResponse
    {
        $this->authorizeItem($document, $item);
        $this->authorizeAction($request, 'commissions.review_documents');
        $data = $request->validate(['reason' => ['required', 'string', 'max:3000']]);
        $service->reject($item, $request->user(), $data['reason']);

        return back()->with('success', 'فاکتور رد و رزرو آن آزاد شد.');
    }

    public function approveCorrection(Request $request, CommissionDocument $document, CommissionDocumentCorrection $correction, CommissionDocumentService $service): RedirectResponse
    {
        abort_unless((int) $correction->commission_document_id === (int) $document->id, 404);
        $this->authorizeAction($request, 'commissions.review_documents');
        $service->reviewCorrection($correction, $request->user(), true);

        return back()->with('success', 'اصلاح پورسانت تأیید شد.');
    }

    public function rejectCorrection(Request $request, CommissionDocument $document, CommissionDocumentCorrection $correction, CommissionDocumentService $service): RedirectResponse
    {
        abort_unless((int) $correction->commission_document_id === (int) $document->id, 404);
        $this->authorizeAction($request, 'commissions.review_documents');
        $data = $request->validate(['reason' => ['required', 'string', 'max:3000']]);
        $service->reviewCorrection($correction, $request->user(), false, $data['reason']);

        return back()->with('success', 'اصلاح پورسانت رد شد؛ سابقه محاسبه تاریخی حفظ شد.');
    }

    public function refreshCandidates(Request $request, CommissionDocument $document, CommissionDocumentService $service): RedirectResponse
    {
        $this->authorizeAction($request, 'commissions.manage_documents');
        $count = $service->refreshCandidates($document, $request->user());

        return back()->with('success', "{$count} فاکتور جدید به سند افزوده شد.");
    }

    public function refreshCalculations(Request $request, CommissionDocument $document, CommissionDocumentService $service): RedirectResponse
    {
        $this->authorizeAction($request, 'commissions.manage_documents');
        $count = $service->refreshCalculations($document, $request->user());

        return back()->with('success', "محاسبات سند به‌روزرسانی شد؛ {$count} فاکتور تغییر داشت و دوباره در انتظار بررسی قرار گرفت.");
    }

    public function search(Request $request, CommissionDocument $document, CommissionDocumentSnapshotService $snapshots): JsonResponse
    {
        $this->authorizeAction($request, 'commissions.manage_documents');
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100']]);
        $term = trim((string) ($data['q'] ?? ''));
        $invoices = Invoice::query()->with(['seller:id,name', 'preinvoiceOrder.seller:id,name', 'preinvoiceOrder.creator:id,name', 'customer'])
            ->when($term !== '', fn ($query) => $query->where(fn ($search) => $search->where('uuid', 'like', "%{$term}%")->orWhere('customer_name', 'like', "%{$term}%")->orWhere('document_date', 'like', "%{$term}%")))
            ->latest('document_date')->limit(20)->get();
        $claims = CommissionDocumentItem::query()->with('document:id,document_number')->whereIn('active_invoice_id', $invoices->pluck('id'))->get()->keyBy('active_invoice_id');
        $sameItems = $document->items()->whereIn('invoice_id', $invoices->pluck('id'))->get()->keyBy('invoice_id');
        $items = $invoices->map(function (Invoice $invoice) use ($document, $snapshots, $claims, $sameItems) {
            $sourcePeriod = $snapshots->sourcePeriod($invoice);
            $snapshot = $snapshots->forInvoice($invoice, $sourcePeriod);
            $claim = $claims->get($invoice->id);
            $same = $sameItems->get($invoice->id);
            $reason = match (true) {
                $invoice->isCancelled() => 'لغوشده',
                (int) $invoice->effective_seller_id !== (int) $document->seller_id => 'فروشنده متفاوت',
                $claim && (int) $claim->commission_document_id !== (int) $document->id => 'در سند '.$claim->document->document_number.' رزرو شده',
                $same && in_array($same->status, ['pending', 'approved'], true) => 'قبلاً در همین سند فعال است',
                ! $sourcePeriod => 'محاسبه نشده',
                $sourcePeriod->needs_recalculation => 'محاسبات دوره نیازمند بروزرسانی است',
                ! $snapshot => 'محاسبه معتبر آماده نیست',
                default => null,
            };

            return ['id' => $invoice->id, 'number' => $invoice->uuid, 'date' => optional($invoice->display_document_date)->format('Y-m-d'),
                'customer' => $invoice->customer_name, 'seller' => $invoice->effectiveSeller()?->name,
                'commission' => $snapshot['total_commission_snapshot'] ?? null,
                'commission_display' => isset($snapshot['total_commission_snapshot']) ? Currency::formatToman($snapshot['total_commission_snapshot']) : null,
                'source_period' => $sourcePeriod?->label,
                'available' => $reason === null, 'reason' => $reason,
                'outside_period' => ! $document->period->contains($invoice->display_document_date)];
        });

        return response()->json(['items' => $items]);
    }

    public function breakdown(Request $request, CommissionDocument $document, CommissionDocumentItem $item, CommissionDocumentSnapshotService $snapshots, CommissionFeatureService $features): JsonResponse
    {
        $this->authorizeDocumentView($request, $document, $features);
        $this->authorizeItem($document, $item);
        $entries = $item->invoice ? $snapshots->entries($item->invoice, $item->sourcePeriod)->map(fn (CommissionLedgerEntry $entry) => [
            'product' => $entry->product_name_snapshot, 'variant' => $entry->variant_name_snapshot, 'quantity' => $entry->quantity_snapshot,
            'net_sale' => $entry->net_amount_snapshot, 'base_rate' => $entry->base_rate_snapshot, 'campaign_rate' => $entry->campaign_rate_snapshot,
            'effective_rate' => $entry->effective_rate_snapshot, 'commission' => $entry->total_commission_amount,
            'net_sale_display' => Currency::formatToman($entry->net_amount_snapshot),
            'commission_display' => Currency::formatToman($entry->total_commission_amount),
        ]) : collect();

        return response()->json(['items' => $entries]);
    }

    public function print(Request $request, CommissionDocument $document, CommissionDocumentService $service): View
    {
        $this->authorizeAction($request, 'commissions.print_documents');
        $document->load(['seller', 'period', 'items', 'corrections', 'adjustments', 'finalizer']);

        return view('commercial.commissions.documents.print', ['document' => $document, 'totals' => $service->totals($document)]);
    }

    public function finalize(Request $request, CommissionDocument $document, CommissionDocumentService $service): RedirectResponse
    {
        $this->authorizeAction($request, 'commissions.finalize_documents');
        $service->finalize($document, $request->user());

        return back()->with('success', 'سند پورسانت نهایی و قفل شد.');
    }

    private function authorizeAction(Request $request, string $permission): void
    {
        abort_unless($request->user()?->hasPermission($permission), 403);
    }

    private function authorizeDocumentView(Request $request, CommissionDocument $document, CommissionFeatureService $features): void
    {
        $user = $request->user();
        $canManage = collect([
            'commissions.view_seller_details',
            'commissions.manage_documents',
            'commissions.review_documents',
            'commissions.finalize_documents',
        ])->contains(fn (string $permission) => $user?->hasPermission($permission));

        $canViewOwn = $features->isSellerVisibilityEnabled()
            && (int) $user?->id === (int) $document->seller_id;

        abort_unless($canManage || $canViewOwn, 403);
    }

    private function authorizeItem(CommissionDocument $document, CommissionDocumentItem $item): void
    {
        abort_unless((int) $item->commission_document_id === (int) $document->id, 404);
    }
}
