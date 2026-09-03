<?php

namespace App\Http\Controllers;

use App\Models\PreinvoiceDraftReservation;
use App\Services\InventoryReservationReleaseService;
use App\Services\ReservationHealthService;
use App\Support\JalaliDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class WarehouseReservationController extends Controller
{
    public function index(Request $request, ReservationHealthService $healthService): JsonResponse|View
    {
        $filters = $request->validate([
            'tab' => ['nullable', 'string', Rule::in(['reservations', 'health', 'orphaned', 'history'])],
            'status' => ['nullable', 'string', Rule::in(PreinvoiceDraftReservation::managementStatuses())],
            'quick' => ['nullable', 'string', Rule::in(PreinvoiceDraftReservation::managementQuickFilters())],
            'search' => ['nullable', 'string', 'max:150'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);
        $evaluatedAt = now();
        $activeTab = $filters['tab'] ?? 'reservations';

        $reservations = $this->emptyPaginator(20, 'page');
        if ($request->expectsJson() || $activeTab === 'reservations') {
            $reservations = PreinvoiceDraftReservation::query()
                ->with([
                    'product:id,name,sku,code',
                    'variant:id,product_id,variant_name,variety_name,variant_code,variety_code',
                    'user:id,name',
                    'order:id,uuid,created_at,updated_at',
                    'order.invoice:id,preinvoice_order_id',
                    'releasedBy:id,name',
                ])
                ->when(
                    ! $request->expectsJson() && ($filters['status'] ?? null) !== PreinvoiceDraftReservation::STATUS_RELEASED,
                    fn ($query) => $query->whereNull('released_at'),
                )
                ->visibleInWarehouseManagement()
                ->excludeOrphaned(
                    PreinvoiceDraftReservation::DEFAULT_ONLINE_STALE_MINUTES,
                    PreinvoiceDraftReservation::DEFAULT_IN_PERSON_STALE_MINUTES,
                    $evaluatedAt,
                )
                ->forManagementStatus($filters['status'] ?? null)
                ->forManagementQuickFilter($filters['quick'] ?? null)
                ->managementSearch($filters['search'] ?? null)
                ->when($filters['date_from'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '>=', $date))
                ->when($filters['date_to'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '<=', $date))
                ->orderByManagementPriority()
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

        $visibleStats = PreinvoiceDraftReservation::query()->visibleInWarehouseManagement();
        $activeStats = (clone $visibleStats)
            ->businessActive($evaluatedAt)
            ->selectRaw('COUNT(*) as aggregate_count, COALESCE(SUM(quantity), 0) as aggregate_quantity')
            ->first();
        $reviewStats = (clone $visibleStats)
            ->needsBusinessAttention($evaluatedAt)
            ->selectRaw('COUNT(*) as aggregate_count, COALESCE(SUM(quantity), 0) as aggregate_quantity')
            ->first();
        $releasableStats = (clone $visibleStats)
            ->abandonedTemporary(
                PreinvoiceDraftReservation::DEFAULT_ONLINE_STALE_MINUTES,
                PreinvoiceDraftReservation::DEFAULT_IN_PERSON_STALE_MINUTES,
                $evaluatedAt,
            )
            ->selectRaw('COUNT(*) as aggregate_count, COALESCE(SUM(quantity), 0) as aggregate_quantity')
            ->first();

        return view('warehouse-reservations.index', [
            'reservations' => $reservations,
            'orphanedReservations' => $orphanedReservations,
            'orphanedCount' => $orphanedCount,
            'healthStats' => $healthStats,
            'healthIssues' => $healthIssues,
            'releasedReservations' => $releasedReservations,
            'filters' => $filters,
            'stats' => [
                'active' => [
                    'count' => (int) $activeStats->aggregate_count,
                    'quantity' => (int) $activeStats->aggregate_quantity,
                ],
                'needs_review' => [
                    'count' => (int) $reviewStats->aggregate_count,
                    'quantity' => (int) $reviewStats->aggregate_quantity,
                ],
                'releasable' => [
                    'count' => (int) $releasableStats->aggregate_count,
                    'quantity' => (int) $releasableStats->aggregate_quantity,
                ],
            ],
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
