<?php

namespace App\Http\Controllers\Tracking;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PreinvoiceOrder;
use App\Services\Tracking\OrderTrackingStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class HallTrackingController extends Controller
{
    public function unlockForm(): Response
    {
        return response()->view('tracking.hall-unlock')
            ->header('Cache-Control', 'private, no-store')
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive');
    }

    public function unlock(Request $request)
    {
        $request->validate(['access_key' => ['required', 'string', 'max:256']]);
        $secret = (string) config('hall_display.access_key', '');

        if (strlen($secret) < 4 || ! hash_equals($secret, (string) $request->input('access_key'))) {
            return back()->withErrors(['access_key' => 'کلید نمایشگر معتبر نیست.']);
        }

        $minutes = (int) config('hall_display.cookie_days', 90) * 24 * 60;
        return redirect()->route('hall.show')->cookie(
            'hall_display_access', hash('sha256', $secret), $minutes,
            '/hall', null, $request->isSecure() || app()->environment('production'),
            true, false, 'strict'
        );
    }

    public function show(): Response
    {
        return response()->view('tracking.customer', [
            'mode' => 'hall',
            'dataUrl' => route('hall.data', absolute: false),
        ])->header('Cache-Control', 'private, no-store, max-age=0')
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function data(): JsonResponse
    {
        // Include all active documents, and recently updated final states.
        // Bound the feed to avoid a huge hall-screen request on busy installations.
        $cutoff = now()->subDays(3);
        $invoices = Invoice::query()
            ->where(fn ($query) => $query->whereNotIn('status', ['shipped', 'not_shipped'])
                ->orWhere('updated_at', '>=', $cutoff))
            ->with('customer:id,name,company_name,first_name,last_name')
            ->orderByDesc('updated_at')
            ->limit(120)
            ->get(['id', 'uuid', 'customer_id', 'status', 'updated_at']);

        $preinvoices = PreinvoiceOrder::query()
            ->where('status', '!=', PreinvoiceOrder::STATUS_DRAFT)
            ->where('status', '!=', PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE)
            ->withoutTemporaryAutosaves()
            ->where(fn ($query) => $query->whereNotIn('status', [
                PreinvoiceOrder::STATUS_RESERVATION_EXPIRED,
                PreinvoiceOrder::STATUS_CANCELLED_BY_WAREHOUSE,
                PreinvoiceOrder::STATUS_CANCELLED_BY_FINANCE,
            ])->orWhere('updated_at', '>=', $cutoff))
            ->with('customer:id,name,company_name,first_name,last_name')
            ->orderByDesc('updated_at')
            ->limit(120)
            ->get(['id', 'uuid', 'customer_id', 'status', 'updated_at']);
        $documents = $invoices->map(function (Invoice $invoice): array {
            [$status, $step, $tone] = OrderTrackingStatus::invoice((string) $invoice->status);
            return [
                'type' => 'invoice', 'type_label' => 'فاکتور',
                'number' => (string) $invoice->uuid,
                'customer' => $this->customerName($invoice->customer),

                'status' => $status, 'step' => $step, 'tone' => $tone,
                'sort_time' => $invoice->updated_at?->timestamp ?? 0,
            ];
        })->concat($preinvoices->map(function (PreinvoiceOrder $order): array {
            [$status, $step, $tone] = OrderTrackingStatus::preinvoice((string) $order->status);
            return [
                'type' => 'preinvoice', 'type_label' => 'پیش‌فاکتور',
                'number' => (string) $order->uuid,
                'customer' => $this->customerName($order->customer),
                'status' => $status, 'step' => $step, 'tone' => $tone,
                'sort_time' => $order->updated_at?->timestamp ?? 0,
            ];
        }))->sortByDesc('sort_time')->take(120)->values()->map(function (array $doc) {
            unset($doc['sort_time']);
            return $doc;
        });

        return response()->json([
            'summary' => [
                'invoices' => $documents->where('type', 'invoice')->count(),
                'preinvoices' => $documents->where('type', 'preinvoice')->count(),
                'total' => $documents->count(),
            ],
            'documents' => $documents,
            'updated_at' => now()->toIso8601String(),
        ])->header('Cache-Control', 'private, no-store, max-age=0')
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->header('Referrer-Policy', 'no-referrer');
    }
    private function customerName(?Customer $customer): string
    {
        if (! $customer) {
            return 'ثبت نشده';
        }

        $name = trim((string) ($customer->name ?: $customer->company_name ?: $customer->display_name));

        return $name !== '' ? $name : 'ثبت نشده';
    }
}
