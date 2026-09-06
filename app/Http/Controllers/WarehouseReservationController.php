<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\PreinvoiceDraftReservation;
use App\Services\InventoryReservationReleaseService;
use App\Services\LegacyReservationCleanupService;
use App\Services\ReservationClassificationService;
use App\Services\ReservationHealthService;
use App\Services\ReservationQueryService;
use App\Support\JalaliDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WarehouseReservationController extends Controller
{
    public function index(
        Request $request,
        ReservationHealthService $healthService,
        ReservationQueryService $reservationQueries,
        ReservationClassificationService $classificationService,
    ): JsonResponse|View
    {
        $filters = $request->validate([
            'tab' => ['nullable', 'string', Rule::in(['reservations', 'health', 'orphaned', 'history'])],
            'status' => ['nullable', 'string', Rule::in(PreinvoiceDraftReservation::managementStatuses())],
            'quick' => ['nullable', 'string', Rule::in(PreinvoiceDraftReservation::managementQuickFilters())],
            'search' => ['nullable', 'string', 'max:150'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
            'classification' => ['nullable', 'string', Rule::in([
                ReservationClassificationService::LABEL_TEMPORARY_ACTIVE,
                ReservationClassificationService::LABEL_TEMPORARY_ORPHAN,
                ReservationClassificationService::LABEL_OFFICIAL_PREINVOICE,
                ReservationClassificationService::LABEL_CRITICAL,
                ReservationClassificationService::LABEL_LEGACY_CANDIDATE,
                ReservationClassificationService::LABEL_CONSUMED,
            ])],
            'lifecycle' => ['nullable', 'string', Rule::in([
                ReservationClassificationService::LIFECYCLE_ACTIVE,
                ReservationClassificationService::LIFECYCLE_RELEASED,
                ReservationClassificationService::LIFECYCLE_CONSUMED,
            ])],
            'age' => ['nullable', 'string', Rule::in(['24h', '72h', '30d'])],
            'user_id' => ['nullable', 'integer', 'exists:users,id'],
            'product_id' => ['nullable', 'integer', 'exists:products,id'],
            'variant_id' => ['nullable', 'integer', 'exists:product_variants,id'],
            'customer_id' => ['nullable', 'integer'],
            'customer_search' => ['nullable', 'string', 'max:150'],
        ]);
        $evaluatedAt = now();
        $activeTab = $filters['tab'] ?? 'reservations';
        $ageHours = match ($filters['age'] ?? null) {
            '24h' => 24,
            '72h' => 72,
            '30d' => 720,
            default => null,
        };

        $reservations = $this->emptyPaginator(20, 'page');
        if ($request->expectsJson() || $activeTab === 'reservations') {
            $reservations = $reservationQueries
                ->filteredManagementQuery([
                    'status' => $filters['status'] ?? null,
                    'quick' => $filters['quick'] ?? null,
                    'search' => $filters['search'] ?? null,
                    'date_from' => $filters['date_from'] ?? null,
                    'date_to' => $filters['date_to'] ?? null,
                    'classification' => $filters['classification'] ?? null,
                    'lifecycle' => $filters['lifecycle'] ?? null,
                    'age_hours' => $ageHours,
                    'user_id' => $filters['user_id'] ?? null,
                    'product_id' => $filters['product_id'] ?? null,
                    'variant_id' => $filters['variant_id'] ?? null,
                    'customer_id' => $filters['customer_id'] ?? null,
                    'customer_search' => $filters['customer_search'] ?? null,
                ], $evaluatedAt)
                ->paginate(20)
                ->withQueryString();
        }

        if ($request->expectsJson()) {
            return response()->json($reservations->through(fn (PreinvoiceDraftReservation $reservation): array => [
                'id' => $reservation->id,
                'token' => $reservation->token,
                'quantity' => $reservation->quantity,
                'status' => $reservation->managementStatus(),
                'business_status' => $reservation->businessStatus(),
                'business_status_label' => $reservation->businessStatusLabel(),
                'classification' => $classificationService->classify($reservation, $evaluatedAt),
                'display_reason' => $reservation->businessDisplayReason(),
                'releasable' => $reservation->isActionableForManagement(),
                'priority' => $reservation->managementPriority(),
                'importance' => $reservation->managementImportance(),
                'age' => $reservation->managementAgeLabel(),
                'warning' => $reservation->managementWarning(),
                'created_at' => $reservation->created_at,
                'created_at_jalali' => JalaliDate::dateTime($reservation->created_at),
                'expires_at' => $reservation->expires_at,
                'last_seen_at' => $reservation->last_seen_at,
                'last_activity_at_jalali' => JalaliDate::dateTime($reservation->managementLastActivityAt()),
                'preinvoice_connected_at_jalali' => JalaliDate::dateTime($reservation->preinvoiceConnectedAt()),
                'released_at' => $reservation->released_at,
                'released_at_jalali' => JalaliDate::dateTime($reservation->released_at),
                'release_reason' => $reservation->release_reason,
                'product' => $reservation->product,
                'variant' => $reservation->variant,
                'created_by' => $reservation->user,
                'preinvoice' => $reservation->order,
                'released_by' => $reservation->releasedBy,
            ]));
        }

        $healthStats = null;
        $healthIssues = null;
        if ($activeTab === 'health') {
            $healthStats = $healthService->summary($evaluatedAt);
            $healthIssues = $healthService->paginateIssues(20, 'health_page', $evaluatedAt);
        }

        $reservationTable = (new PreinvoiceDraftReservation)->getTable();
        $orphanedQuery = PreinvoiceDraftReservation::query()
            ->orphaned(
                PreinvoiceDraftReservation::DEFAULT_ONLINE_STALE_MINUTES,
                PreinvoiceDraftReservation::DEFAULT_IN_PERSON_STALE_MINUTES,
                $evaluatedAt,
            );
        if ($activeTab === 'orphaned') {
            $orphanedReservations = $orphanedQuery
                ->addSelect([
                    'token_group_count' => PreinvoiceDraftReservation::query()
                        ->from("{$reservationTable} as token_group_reservations")
                        ->selectRaw('COUNT(*)')
                        ->whereColumn('token_group_reservations.token', "{$reservationTable}.token")
                        ->whereNull('token_group_reservations.preinvoice_order_id')
                        ->whereNull('token_group_reservations.converted_at')
                        ->whereNull('token_group_reservations.released_at')
                        ->whereNull('token_group_reservations.release_reason')
                        ->where('token_group_reservations.quantity', '>', 0),
                ])
                ->with([
                    'product:id,name,sku,code',
                    'variant:id,product_id,variant_name,variety_name,variant_code,variety_code',
                    'user:id,name',
                ])
                ->oldest('created_at')
                ->oldest('id')
                ->paginate(10, ['*'], 'orphan_page')
                ->withQueryString();
            $orphanedCount = $orphanedReservations->total();
        } else {
            $orphanedReservations = $this->emptyPaginator(10, 'orphan_page');
            $orphanedCount = $orphanedQuery->count();
        }

        $releasedReservations = $this->emptyPaginator(10, 'history_page');
        if ($activeTab === 'history') {
            $releasedReservations = PreinvoiceDraftReservation::query()
                ->whereNotNull('released_at')
                ->whereDoesntHave('order.invoice')
                ->with([
                    'product:id,name,sku,code',
                    'variant:id,product_id,variant_name,variety_name,variant_code,variety_code',
                    'releasedBy:id,name',
                ])
                ->latest('released_at')
                ->latest('id')
                ->paginate(10, ['*'], 'history_page')
                ->withQueryString();
        }

        return view('warehouse-reservations.index', [
            'reservations' => $reservations,
            'orphanedReservations' => $orphanedReservations,
            'orphanedCount' => $orphanedCount,
            'healthStats' => $healthStats,
            'healthIssues' => $healthIssues,
            'releasedReservations' => $releasedReservations,
            'filters' => $filters,
            'stats' => $reservationQueries->dashboardStatistics($evaluatedAt),
            'classificationService' => $classificationService,
        ]);
    }

    /**
     * Phase 4-C — read-only reservation detail page. Never mutates the
     * reservation, its reserved cache, warehouse stock, or any
     * invoice/preinvoice record; it only loads relations and classifies.
     *
     * Known activity-log actions actually written against a
     * PreinvoiceDraftReservation subject in this codebase are
     * 'reservation_manual_release' (InventoryReservationReleaseService) and
     * 'legacy_reservation_cleanup' (LegacyReservationCleanupService). No
     * 'reservation_auto_release' or consumed/converted activity events are
     * logged anywhere today, so only the two real actions are queried —
     * nothing fabricated.
     */
    public function show(
        PreinvoiceDraftReservation $reservation,
        ReservationClassificationService $classificationService,
        ReservationQueryService $reservationQueries,
    ): View {
        $reservation->load([
            'product',
            'variant',
            'user:id,name',
            'order.customer',
            'order.invoice',
            'releasedBy:id,name',
        ]);

        $evaluatedAt = now();
        $classification = $classificationService->classify($reservation, $evaluatedAt);

        $activeReservedQuantity = (int) $reservationQueries
            ->quantitiesByVariant(variantIds: [(int) $reservation->variant_id])
            ->get((int) $reservation->variant_id, 0);

        $activityLogs = ActivityLog::query()
            ->where('subject_type', PreinvoiceDraftReservation::class)
            ->where('subject_id', $reservation->id)
            ->whereIn('action', ['reservation_manual_release', 'legacy_reservation_cleanup'])
            ->with('user:id,name')
            ->orderByDesc('occurred_at')
            ->get();

        return view('warehouse-reservations.show', [
            'reservation' => $reservation,
            'classification' => $classification,
            'classificationService' => $classificationService,
            'activeReservedQuantity' => $activeReservedQuantity,
            'activityLogs' => $activityLogs,
            'evaluatedAt' => $evaluatedAt,
        ]);
    }

    public function exportHealth(ReservationHealthService $healthService): StreamedResponse
    {
        $evaluatedAt = now();

        return response()->streamDownload(function () use ($healthService, $evaluatedAt): void {
            $stream = fopen('php://output', 'wb');
            if ($stream === false) {
                return;
            }

            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, ['کالا', 'تنوع', 'تعداد رزرو', 'مقدار cache', 'نوع مشکل', 'زمان', 'وضعیت']);

            foreach ($healthService->issueRows($evaluatedAt) as $issue) {
                fputcsv($stream, array_map($this->escapeCsvCell(...), [
                    $issue->product_name,
                    $issue->variant_name ?: $issue->variety_name ?: $issue->variant_code ?: $issue->variety_code,
                    (int) $issue->quantity,
                    $issue->cached_quantity === null ? '' : (int) $issue->cached_quantity,
                    $issue->issue_label,
                    $issue->occurred_at,
                    $issue->status_label,
                ]));
            }

            fclose($stream);
        }, 'reservation-health-'.now()->format('Y-m-d-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    public function release(
        Request $request,
        PreinvoiceDraftReservation $reservation,
        InventoryReservationReleaseService $service,
    ): JsonResponse|RedirectResponse {
        $data = $request->validate([
            'release_reason' => ['required', 'string', 'max:255'],
            'release_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $service->releaseDraftReservation(
            $reservation,
            $request->user(),
            $data['release_reason'],
            $data['release_note'] ?? null,
        );

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Reservation released successfully.',
                'reservation_id' => $reservation->getKey(),
            ]);
        }

        return back()->with('success', 'رزرو موجودی با موفقیت آزاد شد.');
    }

    /**
     * Phase 5 — Bulk Normal Release.
     *
     * Manual, per-reservation only — no automatic/force mode. Every ID is
     * reloaded fresh from the database (never trusting whatever state the
     * browser last saw) and released one at a time through the existing
     * authoritative write path, InventoryReservationReleaseService. That
     * service manages its own transaction per call, so one reservation
     * failing never rolls back or double-processes any other reservation in
     * the same request. This method never touches reserved/stock columns or
     * stock_movements directly.
     */
    public function bulkRelease(Request $request, InventoryReservationReleaseService $service): JsonResponse
    {
        $data = $this->validateBulkIds($request, [
            'release_reason' => ['required', 'string', 'max:255'],
            'release_note' => ['nullable', 'string', 'max:2000'],
        ]);
        $ids = $this->normalizedIds($data['reservation_ids']);

        $released = 0;
        $skipped = 0;
        $failed = 0;
        $quantityReleased = 0;
        $items = [];

        foreach ($ids as $id) {
            $reservation = PreinvoiceDraftReservation::query()->find($id);

            if ($reservation === null) {
                $skipped++;
                $items[] = ['id' => $id, 'status' => 'skipped', 'reason' => 'رزرو یافت نشد.'];

                continue;
            }

            $quantity = (int) $reservation->quantity;

            try {
                $service->releaseDraftReservation(
                    $reservation,
                    $request->user(),
                    $data['release_reason'],
                    $data['release_note'] ?? null,
                );
                $released++;
                $quantityReleased += $quantity;
                $items[] = ['id' => $id, 'status' => 'released'];
            } catch (ValidationException $exception) {
                $skipped++;
                $items[] = [
                    'id' => $id,
                    'status' => 'skipped',
                    'reason' => collect($exception->errors())->flatten()->first() ?? 'این رزرو قابل آزادسازی نیست.',
                ];
            } catch (\Throwable $exception) {
                $failed++;
                Log::error('BULK_RESERVATION_RELEASE_FAILED', [
                    'reservation_id' => $id,
                    'exception' => $exception->getMessage(),
                ]);
                $items[] = ['id' => $id, 'status' => 'failed', 'reason' => 'خطای غیرمنتظره در پردازش این رزرو.'];
            }
        }

        return response()->json([
            'requested' => count($ids),
            'released' => $released,
            'skipped' => $skipped,
            'failed' => $failed,
            'quantity_released' => $quantityReleased,
            'items' => $items,
        ]);
    }

    /**
     * Phase 5 — Bulk Legacy Cleanup.
     *
     * Delegates entirely to LegacyReservationCleanupService::cleanup(), which
     * already reloads fresh state, locks each row, and independently
     * re-validates both the classification label and the authoritative SQL
     * legacy scope before closing anything — never trusting the ID list
     * alone. This method adds no cleanup logic of its own and never calls
     * WarehouseStockService, InventoryReservationReleaseService, or creates
     * a stock movement.
     */
    public function bulkLegacyCleanup(Request $request, LegacyReservationCleanupService $service): JsonResponse
    {
        $data = $this->validateBulkIds($request);
        $ids = $this->normalizedIds($data['reservation_ids']);

        $result = $service->cleanup($ids, PreinvoiceDraftReservation::LEGACY_STALE_HOURS, now(), $request->user()?->id);

        $items = collect($result['rows'])->map(fn (array $row): array => [
            'id' => $row['reservation_id'],
            'status' => $row['action'] === LegacyReservationCleanupService::ACTION_CLOSED ? 'closed' : 'skipped',
            'reason' => $row['action'] === LegacyReservationCleanupService::ACTION_CLOSED
                ? null
                : 'این رزرو کاندید Legacy نیست (رزرو رسمی فعال، بحرانی، مصرف‌شده یا متصل به فاکتور است).',
        ])->values()->all();

        // IDs the service could not even find/lock (already released, or a
        // typo/nonexistent ID) never appear in `rows` — report them too.
        // The service's rows are keyed by `reservation_id`, not `id`.
        $foundIds = collect($result['rows'])->pluck('reservation_id')->map(fn (mixed $id): int => (int) $id)->all();
        foreach (array_diff($ids, $foundIds) as $missingId) {
            $items[] = ['id' => $missingId, 'status' => 'skipped', 'reason' => 'رزرو یافت نشد یا قبلاً آزاد شده است.'];
        }

        return response()->json([
            'requested' => count($ids),
            'closed' => $result['closed'],
            'skipped' => count($items) - $result['closed'],
            'failed' => 0,
            'quantity_closed' => $result['quantity_closed'],
            'warehouse_stock_changed' => false,
            'items' => $items,
        ]);
    }

    /**
     * Phase 5 — Bulk CSV Export. Strictly read-only: builds rows from the
     * already-classified reservation data and streams them, never writing
     * to the database.
     */
    public function bulkExport(Request $request, ReservationClassificationService $classificationService): StreamedResponse
    {
        $data = $this->validateBulkIds($request);
        $ids = $this->normalizedIds($data['reservation_ids']);
        $evaluatedAt = now();

        $reservations = PreinvoiceDraftReservation::query()
            ->whereKey($ids)
            ->with([
                'product:id,name',
                'variant:id,product_id,variant_name,variant_code',
                'user:id,name',
                'order:id,uuid,status,customer_id,customer_name,customer_mobile',
                'order.invoice:id,preinvoice_order_id',
            ])
            ->get();

        return response()->streamDownload(function () use ($reservations, $classificationService, $evaluatedAt): void {
            $stream = fopen('php://output', 'wb');
            if ($stream === false) {
                return;
            }

            fwrite($stream, "\xEF\xBB\xBF");
            fputcsv($stream, [
                'reservation_id', 'product_id', 'product_name', 'variant_id', 'variant_name',
                'quantity', 'type', 'lifecycle', 'health', 'classification',
                'creator', 'customer', 'preinvoice_id', 'preinvoice_status', 'invoice_id',
                'created_at', 'last_seen_at', 'released_at', 'release_reason',
            ]);

            foreach ($reservations as $reservation) {
                $classification = $classificationService->classify($reservation, $evaluatedAt);

                fputcsv($stream, array_map($this->escapeCsvCell(...), [
                    $reservation->id,
                    $reservation->product_id,
                    $reservation->product?->name ?? '',
                    $reservation->variant_id,
                    $reservation->variant?->variant_name ?? '',
                    $reservation->quantity,
                    $classification['type'],
                    $classification['lifecycle'],
                    $classification['health'],
                    $classification['label'],
                    $reservation->user?->name ?? '',
                    $reservation->order?->customer_name ?? '',
                    $reservation->preinvoice_order_id,
                    $reservation->order?->status ?? '',
                    $reservation->order?->invoice?->id,
                    optional($reservation->created_at)->toDateTimeString(),
                    optional($reservation->last_seen_at)->toDateTimeString(),
                    optional($reservation->released_at)->toDateTimeString(),
                    $reservation->release_reason ?? '',
                ]));
            }

            fclose($stream);
        }, 'reservation-bulk-export-'.now()->format('Y-m-d-His').'.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }

    /** @return array{reservation_ids: array<int, int>} */
    private function validateBulkIds(Request $request, array $extraRules = []): array
    {
        return $request->validate(array_merge([
            'reservation_ids' => ['required', 'array', 'min:1', 'max:100'],
            'reservation_ids.*' => ['required', 'integer', 'min:1', 'distinct'],
        ], $extraRules));
    }

    /** @return array<int, int> */
    private function normalizedIds(array $ids): array
    {
        return array_values(array_unique(array_map('intval', $ids)));
    }

    private function emptyPaginator(int $perPage, string $pageName): LengthAwarePaginator
    {
        return new LengthAwarePaginator([], 0, $perPage, 1, [
            'path' => LengthAwarePaginator::resolveCurrentPath(),
            'pageName' => $pageName,
        ]);
    }

    private function escapeCsvCell(mixed $value): string
    {
        $value = (string) ($value ?? '');

        return preg_match('/^[\x00-\x20]*[=+\-@]/u', $value) === 1 ? "'{$value}" : $value;
    }
}
