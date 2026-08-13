<?php

namespace App\Services\Finance;

use App\Models\Invoice;
use App\Models\SellerSalesDocument;
use App\Models\SellerSalesDocumentItem;
use App\Models\User;
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
    public const DUPLICATE_MESSAGE = 'یک یا چند فاکتور قبلاً در سند دیگری ثبت شده‌اند. فهرست را دوباره بررسی کنید.';

    public function paginateDocuments(array $filters): LengthAwarePaginator
    {
        return SellerSalesDocument::query()
            ->with(['seller:id,name', 'creator:id,name'])
            ->when(filled($filters['document_number'] ?? null), fn (Builder $query) => $query->where('document_number', 'like', '%'.trim((string) $filters['document_number']).'%'))
            ->when($filters['user_id'] ?? null, fn (Builder $query, $userId) => $query->where('seller_id', $userId))
            ->when($filters['date_from'] ?? null, fn (Builder $query, $date) => $query->whereDate('period_from', '>=', $date))
            ->when($filters['date_to'] ?? null, fn (Builder $query, $date) => $query->whereDate('period_to', '<=', $date))
            ->latest('id')
            ->paginate(20)
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
            ->with(['customer:id,first_name,last_name', 'seller:id,name', 'preinvoiceOrder:id,created_by,seller_id', 'preinvoiceOrder.seller:id,name', 'preinvoiceOrder.creator:id,name'])
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
                );

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
                );

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
    ): Collection {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        [$from, $to] = $this->dateBoundaries($dateFrom, $dateTo);

        $invoices = Invoice::query()
            ->with(['customer:id,first_name,last_name', 'seller:id,name', 'preinvoiceOrder:id,created_by,seller_id', 'preinvoiceOrder.seller:id,name', 'preinvoiceOrder.creator:id,name'])
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
                || $initialDate->lt($from)
                || $initialDate->gt($to)
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
        return [
            'invoice_id' => $invoice->id,
            'status' => SellerSalesDocumentItem::STATUS_ACTIVE,
            'active_invoice_id' => $invoice->id,
            'invoice_number_snapshot' => (string) $invoice->uuid,
            'invoice_date_snapshot' => $this->resolveInvoiceInitialDate($invoice),
            'customer_name_snapshot' => $invoice->customer_name ?: $invoice->customer?->display_name ?: '—',
            'invoice_total_snapshot' => $this->resolveInvoiceFinalAmount($invoice),
        ];
    }

    private function refreshTotals(SellerSalesDocument $document): void
    {
        $document->update([
            'invoice_count' => $document->activeItems()->count(),
            'total_sales_amount' => (int) $document->activeItems()->sum('invoice_total_snapshot'),
        ]);
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
