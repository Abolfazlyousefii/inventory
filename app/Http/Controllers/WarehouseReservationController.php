<?php

namespace App\Http\Controllers;

use App\Models\PreinvoiceDraftReservation;
use App\Services\InventoryReservationReleaseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class WarehouseReservationController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $filters = $request->validate([
            'status' => ['nullable', 'string', Rule::in(PreinvoiceDraftReservation::managementStatuses())],
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
            ->forManagementStatus($filters['status'] ?? null)
            ->managementSearch($filters['search'] ?? null)
            ->when($filters['date_from'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '>=', $date))
            ->when($filters['date_to'] ?? null, fn ($query, $date) => $query->whereDate('created_at', '<=', $date))
            ->latest('created_at')
            ->latest('id')
            ->paginate(20)
            ->withQueryString()
            ->through(fn (PreinvoiceDraftReservation $reservation): array => [
                'id' => $reservation->id,
                'token' => $reservation->token,
                'quantity' => $reservation->quantity,
                'status' => $reservation->managementStatus(),
                'releasable' => $reservation->canBeManuallyReleased(),
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
            ]);

        return response()->json($reservations);
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

        return back()->with('success', 'Reservation released successfully.');
    }
}
