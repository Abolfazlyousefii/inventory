<?php

namespace App\Services\Finance;

use App\Models\Invoice;
use App\Models\SellerSalesDocument;
use App\Models\SellerSalesDocumentAdjustment;
use App\Models\SellerSalesDocumentItem;
use App\Models\User;
use App\Services\Commissions\CommissionMoney;
use App\Services\Commissions\CommissionRateResolver;
use App\Support\ActivityLogger;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SellerCommissionDocumentService
{
    public function __construct(private readonly CommissionRateResolver $rates) {}

    public const DUPLICATE_MESSAGE = 'یک یا چند فاکتور قبلاً در سند دیگری ثبت شده‌اند. فهرست را دوباره بررسی کنید.';

    public function paginateDocuments(array $filters, string $pageName = 'page'): LengthAwarePaginator
    {
        return SellerSalesDocument::query()
            ->with(['seller:id,name', 'creator:id,name'])
            ->when(filled($filters['document_number'] ?? null), fn (Builder $query) => $query->where('document_number', 'like', '%'.trim((string) $filters['document_number']).'%'))
            ->when($filters['user_id'] ?? null, fn (Builder $query, $userId) => $query->where('seller_id', $userId))
            ->when($filters['date_from'] ?? null, fn (Builder $query, $date) => $query->whereDate('period_from', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, $date) => $query->whereDate('period_to', '<=', $date))
            ->latest('id')
            ->paginate(20, ['*'], $pageName)
            ->withQueryString();
    }

    public function getAvailableInvoices(
        int $userId,
        string $dateFrom,
        string $dateTo,
        ?int $currentDocumentId = null,
        ?string $search = null,
    ): Builder {
        [$from, $to] = $this->dateBoundaries($dateFrom, $dateTo);
        $effectiveSeller = Invoice::effectiveSellerSql('invoices', 'commission_preinvoices');

        return Invoice::query()
            ->select('invoices.*')
            ->selectRaw("{$effectiveSeller} as effective_seller_id")
            ->leftJoin('preinvoice_orders as commission_preinvoices', 'commission_preinvoices.id', '=', 'invoices.preinvoice_order_id')
            ->with(['customer:id,first_name,last_name', 'seller:id,name,is_seller,is_active,can_access_erp', 'preinvoiceOrder:id,created_by,seller_id', 'preinvoiceOrder.seller:id,name,is_seller,is_active,can_access_erp', 'preinvoiceOrder.creator:id,name,is_seller,is_active,can_access_erp'])
            ->whereRaw("{$effectiveSeller} = ?", [$userId])
            ->whereBetween(DB::raw('COALESCE(invoices.document_date, invoices.created_at)'), [$from, $to])
            ->where(function (Builder $query): void {
                $query->whereNull('invoices.status')
                    ->orWhereNotIn('invoices.status', Invoice::cancelledStatuses());
            })
            ->whereNotExists(function ($query) use ($currentDocumentId): void {
                $query->selectRaw('1')
                    ->from('seller_sales_document_items as commission_items')
                    ->whereColumn('commission_items.active_invoice_id', 'invoices.id')
                    ->when($currentDocumentId, fn ($inner) => $inner->where('commission_items.seller_sales_document_id', '<>', $currentDocumentId));
            })
            ->when(filled($search), function (Builder $query) use ($search): void {
                $term = '%'.trim((string) $search).'%';
                $query->where(function (Builder $inner) use ($term): void {
                    $inner->where('invoices.uuid', 'like', $term)
                        ->orWhere('invoices.customer_name', 'like', $term);
                });
            })
            ->orderByDesc(DB::raw('COALESCE(invoices.document_date, invoices.created_at)'))
            ->orderByDesc('invoices.id');
    }

    public function paginateAvailable(
        int $userId,
        string $dateFrom,
        string $dateTo,
        ?int $currentDocumentId = null,
        ?string $search = null,
    ): LengthAwarePaginator {
        $this->validUser($userId);

        return $this->getAvailableInvoices($userId, $dateFrom, $dateTo, $currentDocumentId, $search)
            ->paginate(20);
    }

    public function createDocument(array $data, User $actor): SellerSalesDocument
    {
        try {
            return DB::transaction(function () use ($data, $actor): SellerSalesDocument {
                $user = $this->validUser((int) $data['user_id']);
                $invoices = $this->validatedInvoices(
                    $data['invoice_ids'],
                    $user->id,
                    $data['date_from'],
                    $data['date_to'],
                    null,
                    $data['manual_invoice_ids'] ?? [],
                );
                $this->warmRates($invoices);

                $document = SellerSalesDocument::query()->create([
                    'uuid' => (string) Str::uuid(),
                    'document_number' => 'PENDING-'.Str::uuid(),
                    'seller_id' => $user->id,
                    'period_from' => $data['date_from'],
                    'period_to' => $data['date_to'],
                    'invoice_count' => 0,
                    'total_sales_amount' => 0,
                    'notes' => $data['notes'] ?? null,
                    'created_by' => $actor->id,
                    'updated_by' => $actor->id,
                    'status' => SellerSalesDocument::STATUS_DRAFT,
                ]);

                $document->update([
                    'document_number' => 'SC-'.str_pad((string) $document->id, 6, '0', STR_PAD_LEFT),
                ]);

                foreach ($invoices as $invoice) {
                    $document->items()->create($this->snapshot($invoice));
                }

                $this->refreshTotals($document);

                ActivityLogger::log('seller_commission_document.created', $document, 'سند فروش فروشنده ایجاد شد.', [
                    'document_number' => $document->document_number,
                    'seller_user_id' => $user->id,
                    'invoice_ids_added' => $invoices->pluck('id')->all(),
                    'new_total' => $document->total_sales_amount,
                ]);

                return $document->fresh(['items', 'seller', 'creator']);
            });
        } catch (QueryException $exception) {
            $this->throwControlledDuplicate($exception);
            throw $exception;
        }
    }

    public function updateDocument(SellerSalesDocument $document, array $data, User $actor): SellerSalesDocument
    {
        try {
            return DB::transaction(function () use ($document, $data, $actor): SellerSalesDocument {
                $locked = SellerSalesDocument::query()->with(['items', 'activeItems'])->lockForUpdate()->findOrFail($document->id);
                $user = $this->validUser((int) $data['user_id']);
                $invoices = $this->validatedInvoices(
                    $data['invoice_ids'],
                    $user->id,
                    $data['date_from'],
                    $data['date_to'],
                    $locked->id,
                    $data['manual_invoice_ids'] ?? [],
                );
                if (! $locked->isDraft()) {
                    throw ValidationException::withMessages(['document' => 'فقط گزارش پیش‌نویس قابل ویرایش است.']);
                }
                $this->warmRates($invoices);

                $oldIds = $locked->activeItems->pluck('invoice_id')->map(fn ($id) => (int) $id);
                $newIds = $invoices->pluck('id')->map(fn ($id) => (int) $id);
                $addedIds = $newIds->diff($oldIds)->values();
                $removedIds = $oldIds->diff($newIds)->values();
                $oldTotal = (int) $locked->total_sales_amount;

                if ($removedIds->isNotEmpty()) {
                    $locked->activeItems()->whereIn('invoice_id', $removedIds)->delete();
                }

                foreach ($invoices->whereIn('id', $addedIds) as $invoice) {
                    $locked->items()->create($this->snapshot($invoice));
                }

                // Re-apply current rates to kept items so newly defined rates take effect on re-save.
                $keptIds = $newIds->intersect($oldIds);
                foreach ($locked->activeItems->whereIn('invoice_id', $keptIds) as $item) {
                    $fresh = $this->snapshot($invoices->firstWhere('id', $item->invoice_id));
                    $item->update(collect($fresh)->only([
                        'invoice_total_snapshot', 'item_net_amount', 'rate_snapshot', 'rate_source_type',
                        'rate_source_id', 'rate_rule_id', 'commission_amount', 'missing_rate', 'calculation_version',
                    ])->all());
                }

                $locked->update([
                    'seller_id' => $user->id,
                    'period_from' => $data['date_from'],
                    'period_to' => $data['date_to'],
                    'notes' => $data['notes'] ?? null,
                    'updated_by' => $actor->id,
                ]);

                $this->refreshTotals($locked);

                ActivityLogger::log('seller_commission_document.updated', $locked, 'سند فروش فروشنده ویرایش شد.', [
                    'document_number' => $locked->document_number,
                    'seller_user_id' => $user->id,
                    'invoice_ids_added' => $addedIds->all(),
                    'invoice_ids_removed' => $removedIds->all(),
                    'old_total' => $oldTotal,
                    'new_total' => $locked->total_sales_amount,
                ]);

                return $locked->fresh(['items', 'seller', 'creator', 'updater']);
            });
        } catch (QueryException $exception) {
            $this->throwControlledDuplicate($exception);
            throw $exception;
        }
    }

    public function calculateTotals(Collection $invoices): array
    {
        return [
            'invoice_count' => $invoices->count(),
            'total_sales_amount' => (int) $invoices->sum(fn (Invoice $invoice) => $this->resolveInvoiceFinalAmount($invoice)),
        ];
    }

    public function findInvoiceForManualAddition(string $invoiceNumber, int $userId, ?int $currentDocumentId = null): Invoice
    {
        $effectiveSeller = Invoice::effectiveSellerSql('invoices', 'commission_preinvoices');

        $invoice = Invoice::query()
            ->select('invoices.*')
            ->selectRaw("{$effectiveSeller} as effective_seller_id")
            ->leftJoin('preinvoice_orders as commission_preinvoices', 'commission_preinvoices.id', '=', 'invoices.preinvoice_order_id')
            ->with(['customer:id,first_name,last_name', 'seller:id,name,is_seller,is_active,can_access_erp', 'preinvoiceOrder:id,created_by,seller_id', 'preinvoiceOrder.seller:id,name,is_seller,is_active,can_access_erp', 'preinvoiceOrder.creator:id,name,is_seller,is_active,can_access_erp'])
            ->where('invoices.uuid', $invoiceNumber)
            ->first();

        if (! $invoice) {
            abort(404, 'فاکتوری با این شماره یافت نشد.');
        }

        if ($this->resolveInvoiceOwner($invoice) !== $userId) {
            abort(422, 'این فاکتور متعلق به فروشنده انتخاب‌شده نیست.');
        }

        if ($invoice->isCancelled()) {
            abort(422, 'این فاکتور لغو شده و قابل استفاده نیست.');
        }

        if (! $this->resolveInvoiceInitialDate($invoice)) {
            abort(422, 'این فاکتور تاریخ معتبر ندارد.');
        }

        $duplicate = DB::table('seller_sales_document_items')
            ->where('active_invoice_id', $invoice->id)
            ->when($currentDocumentId, fn ($query) => $query->where('seller_sales_document_id', '<>', $currentDocumentId))
            ->exists();

        if ($duplicate) {
            abort(422, 'این فاکتور قبلاً در سند دیگری ثبت شده است.');
        }

        return $invoice;
    }

    public function resolveInvoiceOwner(Invoice $invoice): ?int
    {
        return $invoice->effective_seller_id;
    }

    public function resolveInvoiceInitialDate(Invoice $invoice): ?CarbonImmutable
    {
        $date = $invoice->display_document_date;

        return $date ? CarbonImmutable::instance($date) : null;
    }

    public function resolveInvoiceFinalAmount(Invoice $invoice): int
    {
        return (int) $invoice->total;
    }

    private function validUser(int $id): User
    {
        $user = User::query()->activeErpUsers()->find($id);

        if (! $user) {
            throw ValidationException::withMessages([
                'user_id' => 'کاربر انتخاب‌شده فعال یا مجاز به استفاده از نرم‌افزار نیست.',
            ]);
        }

        return $user;
    }

    private function validatedInvoices(
        array $ids,
        int $userId,
        string $dateFrom,
        string $dateTo,
        ?int $currentDocumentId = null,
        array $manualInvoiceIds = [],
    ): Collection {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        [$from, $to] = $this->dateBoundaries($dateFrom, $dateTo);

        $invoices = Invoice::query()
            ->with(['customer:id,first_name,last_name', 'seller:id,name,is_seller,is_active,can_access_erp', 'preinvoiceOrder:id,created_by,seller_id', 'preinvoiceOrder.seller:id,name,is_seller,is_active,can_access_erp', 'preinvoiceOrder.creator:id,name,is_seller,is_active,can_access_erp'])
            ->whereIn('id', $ids)
            ->lockForUpdate()
            ->get()
            ->sortBy(fn (Invoice $invoice) => array_search($invoice->id, $ids, true))
            ->values();

        if ($invoices->count() !== count($ids)) {
            throw ValidationException::withMessages(['invoice_ids' => 'یک یا چند فاکتور معتبر نیستند.']);
        }

        foreach ($invoices as $invoice) {
            $initialDate = $this->resolveInvoiceInitialDate($invoice);
            if (
                $this->resolveInvoiceOwner($invoice) !== $userId
                || ! $initialDate
                || ((! in_array((int) $invoice->id, array_map('intval', $manualInvoiceIds), true)) && ($initialDate->lt($from) || $initialDate->gt($to)))
                || $invoice->isCancelled()
            ) {
                throw ValidationException::withMessages([
                    'invoice_ids' => 'یک یا چند فاکتور با کاربر، بازه تاریخی یا وضعیت انتخاب‌شده مطابقت ندارند.',
                ]);
            }
        }

        $duplicate = DB::table('seller_sales_document_items')
            ->whereIn('active_invoice_id', $ids)
            ->when($currentDocumentId, fn ($query) => $query->where('seller_sales_document_id', '<>', $currentDocumentId))
            ->exists();

        if ($duplicate) {
            throw ValidationException::withMessages(['invoice_ids' => self::DUPLICATE_MESSAGE]);
        }

        return $invoices;
    }

    private function snapshot(Invoice $invoice): array
    {
        $items = $invoice->items->sortBy('id')->values();
        $invoiceTotal = $this->resolveInvoiceFinalAmount($invoice);

        $weights = $items->map(
            fn ($item) => max(
                (int) $item->quantity * (int) $item->price - (int) ($item->line_discount_amount ?? 0),
                0
            )
        );
        $weightTotal = $weights->sum();

        $referenceDate = $invoice->display_document_date;
        $remaining = $invoiceTotal;
        $rows = collect();

        foreach ($items as $index => $item) {
            $base = ($index === $items->count() - 1)
                ? $remaining
                : ($weightTotal > 0 ? intdiv($weights[$index] * $invoiceTotal, $weightTotal) : 0);
            $remaining -= $base;

            $rate = $this->rates->resolve($item->product, $item->variant, $referenceDate);

            $rows->push([
                'product_id' => (int) $item->product_id,
                'product_variant_id' => $item->variant_id,
                'product_name' => $item->product?->name,
                'variant_name' => $item->variant?->variant_name,
                'quantity' => (int) $item->quantity,
                'commission_base' => $base,
                'rate_source_type' => $rate->sourceType,
                'rate_source_id' => $rate->sourceId,
                'rate_rule_id' => $rate->ruleId,
                'calculated_commission' => $rate->isMissing
                    ? null
                    : CommissionMoney::percentageOf($base, $rate->percentage),
                'missing_rate' => $rate->isMissing,
            ]);
        }

        $activeRows = $rows->where('missing_rate', false);
        $totalCommission = (int) $activeRows->sum('calculated_commission');

        return [
            'invoice_id' => $invoice->id,
            'status' => SellerSalesDocumentItem::STATUS_ACTIVE,
            'active_invoice_id' => $invoice->id,
            'invoice_number_snapshot' => (string) $invoice->uuid,
            'invoice_date_snapshot' => $this->resolveInvoiceInitialDate($invoice),
            'customer_name_snapshot' => $invoice->customer_name ?: $invoice->customer?->display_name ?: '—',
            'invoice_total_snapshot' => $invoiceTotal,
            'product_id' => $rows->count() === 1 ? $rows->first()['product_id'] : null,
            'product_variant_id' => $rows->count() === 1 ? $rows->first()['product_variant_id'] : null,
            'product_name_snapshot' => $rows->count() === 1 ? $rows->first()['product_name'] : 'چند کالا',
            'variant_name_snapshot' => $rows->count() === 1 ? $rows->first()['variant_name'] : null,
            'quantity_snapshot' => (int) $rows->sum('quantity'),
            'rate_snapshot' => $invoiceTotal > 0
                ? number_format($totalCommission * 100 / $invoiceTotal, 4, '.', '')
                : '0.0000',
            'rate_source_type' => $rows->count() === 1 ? $rows->first()['rate_source_type'] : null,
            'rate_source_id' => $rows->count() === 1 ? $rows->first()['rate_source_id'] : null,
            'rate_rule_id' => $rows->count() === 1 ? $rows->first()['rate_rule_id'] : null,
            'item_net_amount' => $invoiceTotal,
            'commission_amount' => $totalCommission,
            'missing_rate' => $rows->contains('missing_rate', true),
            'calculation_version' => 2,
        ];
    }

    private function assertEditable(SellerSalesDocument $document): void
    {
        if (! $document->isDraft()) {
            throw ValidationException::withMessages(['document' => 'این سند در وضعیت پیش‌نویس نیست و قابل ویرایش نمی‌باشد.']);
        }
    }

    public function addAdjustment(SellerSalesDocument $document, int $invoiceId, int $amount, string $reason, int $actorId): SellerSalesDocumentAdjustment
    {
        $this->assertEditable($document);

        $exists = $document->items()
            ->where('invoice_id', $invoiceId)
            ->where('status', SellerSalesDocumentItem::STATUS_ACTIVE)
            ->exists();

        if (! $exists) {
            throw ValidationException::withMessages(['invoice_id' => 'فاکتور مورد نظر در این سند وجود ندارد.']);
        }

        return DB::transaction(function () use ($document, $invoiceId, $amount, $reason, $actorId) {
            $adjustment = $document->adjustments()->create([
                'invoice_id' => $invoiceId,
                'amount' => $amount,
                'reason' => $reason,
                'created_by' => $actorId,
            ]);

            $this->refreshTotals($document);

            ActivityLogger::log('seller_commission_document.adjustment_added', $document, 'تنظیم دستی به سند اضافه شد.', [
                'invoice_id' => $invoiceId,
                'amount' => $amount,
                'reason' => $reason,
            ]);

            return $adjustment;
        });
    }

    public function removeAdjustment(SellerSalesDocument $document, SellerSalesDocumentAdjustment $adjustment, int $actorId): void
    {
        $this->assertEditable($document);

        if ($adjustment->seller_sales_document_id !== $document->id) {
            throw ValidationException::withMessages(['adjustment' => 'این تنظیم متعلق به سند جاری نیست.']);
        }

        DB::transaction(function () use ($document, $adjustment, $actorId) {
            $adjustmentId = $adjustment->id;
            $adjustment->delete();
            $this->refreshTotals($document);

            ActivityLogger::log('seller_commission_document.adjustment_removed', $document, 'تنظیم دستی از سند حذف شد.', [
                'adjustment_id' => $adjustmentId,
            ]);
        });
    }

    public function setBonus(SellerSalesDocument $document, ?int $amount, ?string $reason, int $actorId): void
    {
        $this->assertEditable($document);

        if ($amount !== null && $amount !== 0 && (! $reason || trim($reason) === '')) {
            throw ValidationException::withMessages(['bonus_reason' => 'برای ثبت بونوس، درج دلیل الزامی است.']);
        }

        DB::transaction(function () use ($document, $amount, $reason, $actorId) {
            $document->update([
                'bonus_amount' => $amount ?? 0,
                'bonus_reason' => $reason,
            ]);

            $this->refreshTotals($document);

            ActivityLogger::log('seller_commission_document.bonus_set', $document, 'بونوس سند تنظیم شد.', [
                'bonus_amount' => $amount,
                'bonus_reason' => $reason,
            ]);
        });
    }

    public function confirmDocument(SellerSalesDocument $document, int $actorId): void
    {
        if (! $document->isDraft()) {
            throw ValidationException::withMessages(['document' => 'فقط اسناد پیش‌نویس قابل تأیید هستند.']);
        }

        $missingCount = $document->items()->where('missing_rate', true)->count();
        if ($missingCount > 0) {
            throw ValidationException::withMessages(['document' => "این سند {$missingCount} آیتم بدون نرخ پورسانت دارد. پیش از تأیید، نرخ‌ها را تکمیل کنید."]);
        }

        DB::transaction(function () use ($document, $actorId) {
            $document->update([
                'status' => SellerSalesDocument::STATUS_CONFIRMED,
                'confirmed_by' => $actorId,
                'confirmed_at' => now(),
            ]);

            ActivityLogger::log('seller_commission_document.confirmed', $document, 'سند تأیید شد.');
        });
    }

    public function finalizeDocument(SellerSalesDocument $document, int $actorId): void
    {
        if (! $document->isConfirmed()) {
            throw ValidationException::withMessages(['document' => 'فقط اسناد تأیید‌شده قابل نهایی‌سازی هستند.']);
        }

        DB::transaction(function () use ($document, $actorId) {
            $this->calculateCashCollected($document);

            $document->update([
                'status' => SellerSalesDocument::STATUS_FINALIZED,
                'finalized_by' => $actorId,
                'finalized_at' => now(),
            ]);

            ActivityLogger::log('seller_commission_document.finalized', $document, 'سند نهایی شد.');
        });
    }

    public function calculateCashCollected(SellerSalesDocument $document): void
    {
        $invoiceIds = $document->items()
            ->where('status', SellerSalesDocumentItem::STATUS_ACTIVE)
            ->pluck('invoice_id')
            ->unique();

        $cashTotal = \App\Models\InvoicePayment::whereIn('invoice_id', $invoiceIds)
            ->where('method', 'cash')
            ->sum('amount');

        $document->update([
            'cash_collected_amount' => (int) $cashTotal,
        ]);
    }

    private function refreshTotals(SellerSalesDocument $document): void
    {
        $items = $document->items()->where('status', SellerSalesDocumentItem::STATUS_ACTIVE)->get();

        $totalCommission = (int) $items->sum('commission_amount');
        $totalAdjustment = (int) $document->adjustments()->sum('amount');
        $bonus = (int) $document->bonus_amount;
        $missingCount = $items->where('missing_rate', true)->count();

        $document->update([
            'invoice_count' => $items->count(),
            'total_sales_amount' => (int) $items->sum('item_net_amount'),
            'total_commission_amount' => $totalCommission,
            'total_adjustment_amount' => $totalAdjustment,
            'bonus_amount' => $bonus,
            'net_commission_amount' => $totalCommission + $totalAdjustment + $bonus,
            'missing_rate_count' => $missingCount,
        ]);
    }

    public function deleteDraft(SellerSalesDocument $document): void
    {
        DB::transaction(function () use ($document): void {
            $locked = SellerSalesDocument::query()->lockForUpdate()->findOrFail($document->id);
            if (! $locked->isDraft()) {
                throw ValidationException::withMessages(['document' => 'فقط گزارش پیش‌نویس قابل حذف است.']);
            }
            $locked->items()->delete();
            $locked->delete();
        });
    }

    public function recalculateItems(SellerSalesDocument $document, User $actor): void
    {
        if (! $document->isDraft()) {
            throw new \DomainException('فقط سند پیش‌نویس قابل بازمحاسبه است.');
        }

        DB::transaction(function () use ($document, $actor): void {
            $locked = SellerSalesDocument::query()->lockForUpdate()->findOrFail($document->id);
            if (! $locked->isDraft()) {
                throw new \DomainException('فقط سند پیش‌نویس قابل بازمحاسبه است.');
            }

            $activeItems = $locked->items()
                ->where('status', SellerSalesDocumentItem::STATUS_ACTIVE)
                ->get();

            $invoices = Invoice::query()
                ->with(['items.product.category.parent', 'items.variant', 'customer', 'seller', 'preinvoiceOrder.seller', 'preinvoiceOrder.creator'])
                ->whereIn('id', $activeItems->pluck('invoice_id')->unique()->values())
                ->get()
                ->keyBy('id');

            if ($invoices->isEmpty()) {
                throw new \DomainException('آیتم فعالی برای بازمحاسبه در این سند وجود ندارد.');
            }

            $this->warmRates($invoices->values());

            $oldCommission = (int) $locked->total_commission_amount;
            $oldMissing = (int) $locked->missing_rate_count;

            foreach ($activeItems as $item) {
                $invoice = $invoices->get($item->invoice_id);
                if (! $invoice) {
                    continue;
                }
                $fresh = $this->snapshot($invoice);
                $item->update(collect($fresh)->only([
                    'invoice_total_snapshot', 'item_net_amount', 'rate_snapshot', 'rate_source_type',
                    'rate_source_id', 'rate_rule_id', 'commission_amount', 'missing_rate', 'calculation_version',
                    'product_name_snapshot', 'variant_name_snapshot', 'quantity_snapshot',
                ])->all());
            }

            $locked->update(['updated_by' => $actor->id]);
            $this->refreshTotals($locked);

            ActivityLogger::log('seller_commission_document.recalculated', $locked, 'نرخ‌های سند بازمحاسبه شد.', [
                'document_number' => $locked->document_number,
                'actor_id' => $actor->id,
                'old_total_commission' => $oldCommission,
                'new_total_commission' => (int) $locked->total_commission_amount,
                'old_missing_rate_count' => $oldMissing,
                'new_missing_rate_count' => (int) $locked->missing_rate_count,
            ]);
        });
    }

    private function warmRates(Collection $invoices): void
    {
        $invoices->loadMissing(['items.product.category.parent', 'items.variant', 'seller', 'preinvoiceOrder.seller', 'preinvoiceOrder.creator']);
        $dates = $invoices->map(fn (Invoice $invoice) => CarbonImmutable::parse($invoice->display_document_date));
        $this->rates->warm($dates->min()->startOfDay(), $dates->max()->addDay()->startOfDay());
    }

    private function dateBoundaries(string $dateFrom, string $dateTo): array
    {
        return [
            CarbonImmutable::createFromFormat('!Y-m-d', $dateFrom, config('app.timezone'))->startOfDay(),
            CarbonImmutable::createFromFormat('!Y-m-d', $dateTo, config('app.timezone'))->endOfDay(),
        ];
    }

    private function throwControlledDuplicate(QueryException $exception): void
    {
        $message = strtolower($exception->getMessage());
        $isUniqueViolation = in_array((string) $exception->getCode(), ['23000', '23505'], true)
            || str_contains($message, 'unique constraint')
            || str_contains($message, 'duplicate entry');

        if ($isUniqueViolation) {
            throw ValidationException::withMessages(['invoice_ids' => self::DUPLICATE_MESSAGE]);
        }
    }
}
