<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\SellerReassignmentAudit;
use App\Models\SellerSalesDocumentItem;
use App\Models\User;
use App\Services\SalesDocumentSellerReassignmentService;
use App\Support\Currency;
use App\Support\JalaliDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CommercialInvoiceReassignmentController extends Controller
{
    public function __construct(
        private readonly SalesDocumentSellerReassignmentService $sellerReassignmentService,
    ) {}

    public function index(Request $request): View
    {
        $history = SellerReassignmentAudit::query()
            ->with([
                'invoice:id,uuid,total,customer_name',
                'oldSeller:id,name',
                'newSeller:id,name',
                'changedByUser:id,name',
            ])
            ->withCount('releasedCommissionItems')
            ->orderByDesc('changed_at')
            ->orderByDesc('id')
            ->paginate(20, ['*'], 'history_page')
            ->withQueryString();

        return view('commercial.invoice-reassignments.index', [
            'sellers' => $this->sellers(),
            'history' => $history,
        ]);
    }

    public function search(Request $request): JsonResponse
    {
        $data = $request->validate([
            'q' => ['nullable', 'string', 'max:150'],
            'seller_id' => ['nullable', 'integer', 'exists:users,id'],
        ]);

        $term = trim((string) ($data['q'] ?? ''));
        $normalizedTerm = $this->normalizeDigits($term);
        $sellerId = isset($data['seller_id']) ? (int) $data['seller_id'] : null;

        $query = Invoice::query()
            ->leftJoin('preinvoice_orders', 'preinvoice_orders.id', '=', 'invoices.preinvoice_order_id')
            ->select('invoices.*')
            ->with([
                'customer:id,first_name,last_name,mobile',
                'seller:id,name',
                'preinvoiceOrder:id,seller_id,created_by',
                'preinvoiceOrder.seller:id,name',
                'preinvoiceOrder.creator:id,name',
            ]);

        if ($sellerId) {
            $query->whereRaw(Invoice::effectiveSellerSql().' = ?', [$sellerId]);
        }

        if ($term !== '') {
            $likeTerm = '%'.$term.'%';
            $likeNormalized = '%'.$normalizedTerm.'%';

            $query->where(function ($builder) use ($likeTerm, $likeNormalized): void {
                $builder
                    ->where('invoices.uuid', 'like', $likeNormalized)
                    ->orWhere('invoices.customer_name', 'like', $likeTerm)
                    ->orWhere('invoices.customer_mobile', 'like', $likeNormalized)
                    ->orWhereHas('customer', function ($customerQuery) use ($likeTerm, $likeNormalized): void {
                        $customerQuery
                            ->where('first_name', 'like', $likeTerm)
                            ->orWhere('last_name', 'like', $likeTerm)
                            ->orWhere('mobile', 'like', $likeNormalized);
                    })
                    ->orWhereHas('seller', fn ($sellerQuery) => $sellerQuery->where('name', 'like', $likeTerm))
                    ->orWhereHas('preinvoiceOrder.seller', fn ($sellerQuery) => $sellerQuery->where('name', 'like', $likeTerm))
                    ->orWhereHas('preinvoiceOrder.creator', fn ($creatorQuery) => $creatorQuery->where('name', 'like', $likeTerm));
            });
        }

        $invoices = $query
            ->orderByDesc('invoices.created_at')
            ->orderByDesc('invoices.id')
            ->limit(50)
            ->get();

        $claims = $this->activeCommissionClaims($invoices->pluck('id')->map(fn ($id) => (int) $id)->all());

        return response()->json([
            'data' => $invoices->map(fn (Invoice $invoice) => $this->invoicePayload($invoice, $claims->get($invoice->id, collect())))->values(),
        ]);
    }

    public function preview(Request $request): JsonResponse
    {
        $data = $request->validate([
            'invoice_ids' => ['required', 'array', 'min:1', 'max:100'],
            'invoice_ids.*' => ['required', 'integer', 'distinct', 'exists:invoices,id'],
            'seller_id' => ['required', 'integer', 'exists:users,id'],
            'reason' => ['required', 'string', 'max:1000'],
            'sync_preinvoice' => ['required', 'boolean'],
        ]);

        $seller = $this->activeSeller((int) $data['seller_id']);
        $ids = collect($data['invoice_ids'])->map(fn ($id) => (int) $id)->unique()->sort()->values();

        $invoices = Invoice::query()
            ->whereIn('id', $ids)
            ->with([
                'customer:id,first_name,last_name,mobile',
                'seller:id,name',
                'preinvoiceOrder:id,seller_id,created_by',
                'preinvoiceOrder.seller:id,name',
                'preinvoiceOrder.creator:id,name',
            ])
            ->orderBy('id')
            ->get();

        $claims = $this->activeCommissionClaims($ids->all());
        $rows = $invoices->map(function (Invoice $invoice) use ($seller, $claims): array {
            $invoiceClaims = $claims->get($invoice->id, collect());
            $wrongClaims = $invoiceClaims->filter(
                fn (SellerSalesDocumentItem $item): bool => (int) ($item->document?->seller_id ?? 0) !== (int) $seller->id
            );

            return [
                ...$this->invoicePayload($invoice, $invoiceClaims),
                'seller_will_change' => (int) ($invoice->effective_seller_id ?? 0) !== (int) $seller->id,
                'commission_claims_to_release' => $wrongClaims->count(),
            ];
        })->values();

        return response()->json([
            'preview_token' => $this->previewToken(
                $invoices,
                $seller,
                $claims,
                $request->user()->id,
                trim((string) $data['reason']),
                (bool) $data['sync_preinvoice'],
            ),
            'summary' => [
                'invoice_count' => $rows->count(),
                'seller_change_count' => $rows->where('seller_will_change', true)->count(),
                'commission_release_count' => (int) $rows->sum('commission_claims_to_release'),
                'already_destination_count' => $rows->where('seller_will_change', false)->count(),
                'total_rial' => (int) $invoices->sum('total'),
                'total_display' => Currency::formatToman((int) $invoices->sum('total')),
                'destination_seller' => ['id' => (int) $seller->id, 'name' => (string) $seller->name],
                'sync_preinvoice' => (bool) $data['sync_preinvoice'],
            ],
            'data' => $rows,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'invoice_ids' => ['required', 'array', 'min:1', 'max:100'],
            'invoice_ids.*' => ['required', 'integer', 'distinct', 'exists:invoices,id'],
            'seller_id' => ['required', 'integer', 'exists:users,id'],
            'reason' => ['required', 'string', 'max:1000'],
            'sync_preinvoice' => ['required', 'boolean'],
            'preview_token' => ['required', 'string', 'size:64'],
        ]);

        $seller = $this->activeSeller((int) $data['seller_id']);
        $ids = collect($data['invoice_ids'])->map(fn ($id) => (int) $id)->unique()->sort()->values();

        $results = DB::transaction(function () use ($data, $ids, $seller, $request): Collection {
            $previewInvoices = Invoice::query()
                ->whereIn('id', $ids)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $preinvoiceIds = $previewInvoices->pluck('preinvoice_order_id')->filter()->map(fn ($id) => (int) $id)->unique()->sort()->values();
            if ($preinvoiceIds->isNotEmpty()) {
                DB::table('preinvoice_orders')->whereIn('id', $preinvoiceIds)->orderBy('id')->lockForUpdate()->get(['id']);
            }
            $previewInvoices->load('preinvoiceOrder:id,seller_id,created_by');

            $previewClaims = $this->activeCommissionClaims($ids->all(), true);
            $expectedPreviewToken = $this->previewToken(
                $previewInvoices,
                $seller,
                $previewClaims,
                $request->user()->id,
                trim((string) $data['reason']),
                (bool) $data['sync_preinvoice'],
            );
            if (! hash_equals($expectedPreviewToken, (string) $data['preview_token'])) {
                throw ValidationException::withMessages([
                    'preview_token' => 'وضعیت فاکتورها یا اطلاعات انتقال از زمان پیش‌نمایش تغییر کرده است؛ پیش‌نمایش را دوباره بررسی کنید.',
                ]);
            }

            return collect($this->sellerReassignmentService->reassignMany(
                $ids->all(),
                $seller,
                $request->user(),
                (string) $data['reason'],
                (bool) $data['sync_preinvoice'],
                'commercial_ui',
                (string) Str::uuid(),
            ));
        });

        $changed = $results->where('changed', true)->count();
        $repaired = $results->where('commissionClaimRepaired', true)->count();
        $releasedClaims = (int) $results->sum('releasedCommissionClaims');
        $unchanged = $results->count() - $changed;

        if ($changed > 0) {
            $message = "{$changed} فاکتور با موفقیت به {$seller->name} منتقل شد";
            if ($releasedClaims > 0) {
                $message .= " و {$releasedClaims} اتصال فعال از اسناد پورسانت قبلی آزاد شد";
            }
            if ($unchanged > 0) {
                $message .= "؛ {$unchanged} فاکتور از قبل متعلق به فروشنده مقصد بود";
            }
            $message .= '.';
        } elseif ($repaired > 0) {
            $message = "فروشنده فاکتورهای انتخاب‌شده از قبل {$seller->name} بود؛ {$releasedClaims} اتصال ناسازگار پورسانتی اصلاح و آزاد شد.";
        } else {
            $message = "فروشنده و وضعیت پورسانت فاکتورهای انتخاب‌شده از قبل برای {$seller->name} صحیح بود.";
        }

        return redirect()
            ->route('commercial.invoice-reassignments.index')
            ->with('success', $message);
    }

    private function sellers(): Collection
    {
        return User::query()
            ->activeSellers()
            ->orderBy('name')
            ->get(['id', 'name']);
    }

    private function activeSeller(int $sellerId): User
    {
        $seller = User::query()->activeSellers()->find($sellerId);
        if (! $seller) {
            throw ValidationException::withMessages([
                'seller_id' => 'فروشنده مقصد باید کاربر فعال و مجاز ERP باشد.',
            ]);
        }

        return $seller;
    }

    /** @return Collection<int, Collection<int, SellerSalesDocumentItem>> */
    private function activeCommissionClaims(array $invoiceIds, bool $lockForUpdate = false): Collection
    {
        if ($invoiceIds === []) {
            return collect();
        }

        $query = SellerSalesDocumentItem::query()
            ->active()
            ->whereIn('active_invoice_id', $invoiceIds)
            ->with(['document.seller:id,name'])
            ->orderBy('id');

        if ($lockForUpdate) {
            $query->lockForUpdate();
        }

        return $query
            ->get()
            ->groupBy(fn (SellerSalesDocumentItem $item): int => (int) $item->active_invoice_id);
    }

    private function invoicePayload(Invoice $invoice, Collection $claims): array
    {
        $seller = $invoice->effectiveSeller();

        return [
            'id' => (int) $invoice->id,
            'number' => (string) $invoice->uuid,
            'date' => JalaliDate::date($invoice->display_document_date),
            'customer' => (string) ($invoice->customer_name ?: $invoice->customer?->display_name ?: '—'),
            'mobile' => (string) ($invoice->customer_mobile ?: $invoice->customer?->mobile ?: ''),
            'total_rial' => (int) $invoice->total,
            'total_display' => Currency::formatToman((int) $invoice->total),
            'status' => (string) $invoice->status,
            'status_label' => Invoice::statusLabels()[$invoice->status] ?? (string) $invoice->status,
            'is_cancelled' => $invoice->isCancelled(),
            'seller' => $seller ? ['id' => (int) $seller->id, 'name' => (string) $seller->name] : null,
            'commission_claims' => $claims->map(fn (SellerSalesDocumentItem $item): array => [
                'item_id' => (int) $item->id,
                'document_id' => (int) $item->seller_sales_document_id,
                'document_number' => (string) ($item->document?->document_number ?? '—'),
                'seller_id' => (int) ($item->document?->seller_id ?? 0),
                'seller_name' => (string) ($item->document?->seller?->name ?? '—'),
                'amount_display' => Currency::formatToman((int) $item->invoice_total_snapshot),
            ])->values(),
        ];
    }

    private function previewToken(
        Collection $invoices,
        User $seller,
        Collection $claims,
        int $actorId,
        string $reason,
        bool $syncPreinvoice,
    ): string {
        $state = $invoices
            ->sortBy('id')
            ->map(function (Invoice $invoice) use ($claims): array {
                $claimState = $claims->get($invoice->id, collect())
                    ->sortBy('id')
                    ->map(fn (SellerSalesDocumentItem $item): array => [
                        'id' => (int) $item->id,
                        'active_invoice_id' => (int) $item->active_invoice_id,
                        'document_seller_id' => (int) ($item->document?->seller_id ?? 0),
                    ])
                    ->values()
                    ->all();

                return [
                    'id' => (int) $invoice->id,
                    'effective_seller_id' => $invoice->effective_seller_id,
                    'preinvoice_seller_id' => $invoice->preinvoiceOrder?->seller_id,
                    'claims' => $claimState,
                ];
            })
            ->values()
            ->all();

        $payload = json_encode([
            'actor_id' => $actorId,
            'destination_seller_id' => (int) $seller->id,
            'reason' => trim($reason),
            'sync_preinvoice' => $syncPreinvoice,
            'state' => $state,
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);

        return hash_hmac('sha256', $payload, (string) config('app.key'));
    }

    private function normalizeDigits(string $value): string
    {
        return strtr($value, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }
}
