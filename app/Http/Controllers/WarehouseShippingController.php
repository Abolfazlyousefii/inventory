<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Invoice;
use App\Models\ShippingMethod;
use App\Services\NotificationService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class WarehouseShippingController extends Controller
{
    public function __construct(private readonly NotificationService $notificationService) {}

    public function index(): View
    {
        $invoicesQuery = Invoice::query()
            ->with(['items:id,invoice_id,quantity', 'preinvoiceOrder.creator:id,name'])
            ->where('status', Invoice::STATUS_READY_TO_SHIP);

        $summary = [
            'ready_count' => (clone $invoicesQuery)->count(),
            'shipped_today' => Invoice::query()
                ->where('status', Invoice::STATUS_SHIPPED)
                ->whereDate('shipped_at', now()->toDateString())
                ->count(),
            'ready_total' => (int) (clone $invoicesQuery)->sum('total'),
        ];

        $invoices = $invoicesQuery
            ->orderBy('status_changed_at')
            ->orderBy('id')
            ->paginate(20)
            ->withQueryString();

        $shippingMethods = ShippingMethod::query()
            ->orderBy('name')
            ->get(['id', 'name', 'price']);

        return view('warehouse.shipping.index', compact('invoices', 'shippingMethods', 'summary'));
    }

    public function ship(Request $request, string $uuid): RedirectResponse
    {
        $normalizedCost = $this->normalizeUnsignedInteger($request->input('shipping_cost'));
        $request->merge(['shipping_cost' => $normalizedCost]);

        $validated = $request->validate([
            'shipping_method_id' => ['required', 'integer', 'exists:shipping_methods,id'],
            'shipping_cost' => ['nullable', 'integer', 'min:0'],
            'shipping_note' => ['nullable', 'string', 'max:2000'],
        ]);

        $invoice = null;
        $shippingMethod = null;

        DB::transaction(function () use ($uuid, $validated, &$invoice, &$shippingMethod): void {
            $invoice = Invoice::query()->where('uuid', $uuid)->lockForUpdate()->firstOrFail();

            if ((string) $invoice->status !== Invoice::STATUS_READY_TO_SHIP) {
                abort(422, 'این فاکتور در وضعیت آماده ارسال نیست.');
            }

            $shippingMethod = ShippingMethod::query()->whereKey($validated['shipping_method_id'])->firstOrFail();

            $invoice->forceFill([
                'shipping_method_id' => (int) $shippingMethod->id,
                'shipping_cost' => (int) ($validated['shipping_cost'] ?? 0),
                'shipping_note' => $validated['shipping_note'] ?? null,
                'shipped_at' => now(),
                'shipped_by' => auth()->id(),
                'status' => Invoice::STATUS_SHIPPED,
                'status_changed_at' => now(),
                'status_changed_by' => auth()->id(),
            ])->save();

            ActivityLog::create([
                'user_id' => auth()->id(),
                'action' => 'invoice_shipped',
                'subject_type' => Invoice::class,
                'subject_id' => $invoice->id,
                'description' => 'ارسال فاکتور ثبت شد.',
                'properties' => [
                    'invoice_uuid' => $invoice->uuid,
                    'shipping_method_id' => $shippingMethod->id,
                    'shipping_method_name' => $shippingMethod->name,
                    'shipping_cost' => (int) ($validated['shipping_cost'] ?? 0),
                ],
                'occurred_at' => now(),
            ]);
        });

        $this->notifySeller($invoice, $shippingMethod);

        return redirect()->route('warehouse.shipping.index')->with('success', 'ارسال فاکتور ثبت شد.');
    }

    private function normalizeUnsignedInteger(mixed $value): int
    {
        $value = trim((string) $value);

        if ($value === '') {
            return 0;
        }

        $persian = ['۰','۱','۲','۳','۴','۵','۶','۷','۸','۹','٠','١','٢','٣','٤','٥','٦','٧','٨','٩'];
        $english = ['0','1','2','3','4','5','6','7','8','9','0','1','2','3','4','5','6','7','8','9'];
        $value = str_replace($persian, $english, $value);
        $value = str_replace([',', '٬', '،', ' ', '_'], '', $value);

        return ctype_digit($value) ? (int) $value : -1;
    }

    private function notifySeller(?Invoice $invoice, ?ShippingMethod $shippingMethod): void
    {
        if (! $invoice || ! $shippingMethod) {
            return;
        }

        try {
            $invoice->loadMissing('preinvoiceOrder.creator:id,name');
            $sellerId = $invoice->preinvoiceOrder?->created_by;

            if (! $sellerId) {
                return;
            }

            $this->notificationService->notifyUserAfterCommit(
                (int) $sellerId,
                'invoice_shipped',
                'فاکتور ارسال شد',
                'فاکتور شماره ' . $invoice->uuid . ' برای مشتری ' . ($invoice->customer_name ?: '---') . ' با روش ارسال ' . $shippingMethod->name . ' ارسال شد.',
                route('vouchers.sales.show', $invoice->uuid),
                ['level' => 'success', 'notifiable_type' => Invoice::class, 'notifiable_id' => $invoice->id, 'unique_key' => 'invoice_shipped:' . $invoice->id]
            );
        } catch (\Throwable $e) {
            Log::warning('Shipping notification failed', [
                'invoice_id' => $invoice->id ?? null,
                'error' => $e->getMessage(),
            ]);
        }
    }
}
