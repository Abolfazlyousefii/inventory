<?php

namespace App\Http\Controllers;

use App\Models\PreinvoiceDraftReservation;
use App\Services\InventoryReservationReleaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class WarehouseReservationController extends Controller
{
    public function index(Request $request): JsonResponse|View
    {
        $filters = $request->validate([
            'status' => ['nullable', 'string', Rule::in(PreinvoiceDraftReservation::managementStatuses())],
            'quick' => ['nullable', 'string', Rule::in(PreinvoiceDraftReservation::managementQuickFilters())],
            'search' => ['nullable', 'string', 'max:150'],
            'date_from' => ['nullable', 'date'],
            'date_to' => ['nullable', 'date', 'after_or_equal:date_from'],
        ]);

        $reservations = PreinvoiceDraftReservation::query()
            ->with([
                'product:id,name,sku,code',
                'variant:id,product_id,variant_name,variety_name,variant_code,variety_code',
                'user:id,name',
                'order:id,uuid',
                'releasedBy:id,name',
            ])
            ->when(
                ! $request->expectsJson() && ($filters['status'] ?? null) !== PreinvoiceDraftReservation::STATUS_RELEASED,
                fn ($query) => $query->whereNull('released_at'),
            )
            ->forManagementStatus($filters['status'] ?? null)
            ->forManagementQuickFilter($filters['quick'] ?? null)
            ->managementSearch($filters['search'] ?? null)
            ->when($filters['date_from'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '<=', $date))
            ->orderByManagementPriority()
            ->paginate(20)
            ->withQueryString();

        if ($request->expectsJson()) {
            return response()->json($reservations->through(fn (PreinvoiceDraftReservation $reservation): array => [
                'id' => $reservation->id,
                'token' => $reservation->token,
                'quantity' => $reservation->quantity,
                'status' => $reservation->managementStatus(),
                'releasable' => $reservation->isActionableForManagement(),
                'priority' => $reservation->managementPriority(),
                'importance' => $reservation->managementImportance(),
                'age' => $reservation->managementAgeLabel(),
                'warning' => $reservation->managementWarning(),
                'created_at' => $reservation->created_at,
                'expires_at' => $reservation->expires_at,
                'last_seen_at' => $reservation->last_seen_at,
                'released_at' => $reservation->released_at,
                'release_reason' => $reservation->release_reason,
                'product' => $reservation->product,
                'variant' => $reservation->variant,
                'created_by' => $reservation->user,
                'preinvoice' => $reservation->order,
                'released_by' => $reservation->releasedBy,
            ]));
        }

        $releasedReservations = PreinvoiceDraftReservation::query()
            ->whereNotNull('released_at')
            ->with([
                'product:id,name,sku,code',
                'variant:id,product_id,variant_name,variety_name,variant_code,variety_code',
                'releasedBy:id,name',
            ])
            ->latest('released_at')
            ->latest('id')
            ->paginate(10, ['*'], 'history_page')
            ->withQueryString();

        $activeStats = PreinvoiceDraftReservation::query()
            ->where(function ($query): void {
                $query->activeTemporary()->orWhere(fn ($query) => $query->connected());
            })
            ->selectRaw('COUNT(*) as aggregate_count, COALESCE(SUM(quantity), 0) as aggregate_quantity')
            ->first();
        $reviewStats = PreinvoiceDraftReservation::query()
            ->needsManagementReview()
            ->selectRaw('COUNT(*) as aggregate_count, COALESCE(SUM(quantity), 0) as aggregate_quantity')
            ->first();
        $releasableStats = PreinvoiceDraftReservation::query()
            ->abandonedTemporary()
            ->selectRaw('COUNT(*) as aggregate_count, COALESCE(SUM(quantity), 0) as aggregate_quantity')
            ->first();

        return view('warehouse-reservations.index', [
            'reservations' => $reservations,
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
}
