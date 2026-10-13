<?php

namespace App\Http\Controllers\Tracking;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\PreinvoiceOrder;
use App\Services\Tracking\OrderTrackingStatus;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Response;
use Morilog\Jalali\Jalalian;

final class CustomerTrackingController extends Controller
{
    public function show(Request $request, string $access): Response
    {
        $this->customerFromAccess($access);

        // The data endpoint gets a separate signature with exactly the same expiration.
        $dataUrl = URL::temporarySignedRoute(
            'tracking.data',
            now()->setTimestamp((int) $request->query('expires')),
            ['access' => $access],
            absolute: false
        );

        return response()->view('tracking.customer', ['dataUrl' => $dataUrl, 'mode' => 'customer'])
            ->header('Cache-Control', 'private, no-store, max-age=0')
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->header('Referrer-Policy', 'no-referrer');
    }

    public function data(string $access): JsonResponse
    {
        $customer = $this->customerFromAccess($access);

        $invoices = Invoice::query()
            ->where('customer_id', $customer->getKey())
            ->withCount('items')
            ->withSum('items as quantity_total', 'quantity')
            ->with([
                'seller:id,name',
                'preinvoiceOrder' => fn ($query) => $query
                    ->where('customer_id', $customer->getKey())
                    ->select('id', 'seller_id', 'created_by', 'uuid'),
                'preinvoiceOrder.seller:id,name',
                'preinvoiceOrder.creator:id,name',
            ])
            ->orderByDesc('created_at')
            ->get();

        $preinvoices = PreinvoiceOrder::query()
            ->where('customer_id', $customer->getKey())
            ->where('status', '!=', PreinvoiceOrder::STATUS_DRAFT)
            ->withoutTemporaryAutosaves()
            ->withCount('items')
            ->withSum('items as quantity_total', 'quantity')
            ->with([
                'seller:id,name',
                'creator:id,name',
                'invoice' => fn ($query) => $query
                    ->where('customer_id', $customer->getKey())
                    ->select('id', 'preinvoice_order_id', 'uuid'),
            ])
            ->orderByDesc('created_at')
            ->get();

        $documents = $invoices->map(fn (Invoice $invoice) => $this->invoiceData($invoice))
            ->concat($preinvoices->map(fn (PreinvoiceOrder $order) => $this->preinvoiceData($order)))
            ->sortByDesc('sort_time')
            ->values()
            ->map(function (array $document) {
                unset($document['sort_time']);
                return $document;
            });

        $name = trim((string) ($customer->name ?: ($customer->company_name ?: trim(
            (string) $customer->first_name . ' ' . (string) $customer->last_name
        ))));

        return response()->json([
            'customer' => ['name' => $name !== '' ? $name : 'مشتری گرامی'],
            'summary' => [
                'invoices' => $invoices->count(),
                'preinvoices' => $preinvoices->count(),
                'total' => $documents->count(),
            ],
            'documents' => $documents,
            'updated_at' => now()->toIso8601String(),
        ])->header('Cache-Control', 'private, no-store, max-age=0')
            ->header('X-Robots-Tag', 'noindex, nofollow, noarchive')
            ->header('Referrer-Policy', 'no-referrer');
    }

    /** Decrypt one URL-safe reference only after the signed-route middleware validates it. */
    private function customerFromAccess(string $access): Customer
    {
        if (! preg_match('/^[A-Za-z0-9_-]{20,1024}$/D', $access)) {
            abort(404);
        }

        try {
            $id = Crypt::decryptString(
                strtr($access, '-_', '+/') . str_repeat('=', (4 - strlen($access) % 4) % 4)
            );
        } catch (DecryptException|\TypeError $e) {
            abort(404);
        }

        if (! ctype_digit($id) || (int) $id < 1) {
            abort(404);
        }

        return Customer::query()->whereKey((int) $id)->where('is_active', true)->firstOrFail();
    }

    private function invoiceData(Invoice $invoice): array
    {
        $status = OrderTrackingStatus::invoice((string) $invoice->status);

        $date = $invoice->display_document_date;

        return [
            'id' => 'invoice-' . $invoice->getKey(),
            'type' => 'invoice',
            'type_label' => 'فاکتور',
            'number' => (string) ($invoice->uuid ?: $invoice->getKey()),
            'public_reference' => OrderTrackingStatus::reference('invoice', (int) $invoice->getKey()),
            'status' => $status[0],
            'step' => $status[1],
            'tone' => $status[2],
            'seller' => $invoice->seller?->name
                        ?? $invoice->preinvoiceOrder?->seller?->name
                           ?? $invoice->preinvoiceOrder?->creator?->name
                              ?? 'ثبت نشده',
            'item_count' => (int) $invoice->items_count,
            'quantity' => (int) ($invoice->quantity_total ?? 0),
            'date' => $date ? Jalalian::fromDateTime($date)->format('Y/m/d') : '—',
            'related_number' => $invoice->preinvoiceOrder?->uuid,
            'sort_time' => $date?->timestamp ?? 0,
        ];
    }

    private function preinvoiceData(PreinvoiceOrder $order): array
    {
        $status = OrderTrackingStatus::preinvoice((string) $order->status);

        $date = $order->display_document_date;

        return [
            'id' => 'preinvoice-' . $order->getKey(),
            'type' => 'preinvoice',
            'type_label' => 'پیش‌فاکتور',
            'number' => (string) ($order->uuid ?: $order->getKey()),
            'public_reference' => OrderTrackingStatus::reference('preinvoice', (int) $order->getKey()),
            'status' => $status[0],
            'step' => $status[1],
            'tone' => $status[2],
            'seller' => $order->seller?->name ?? $order->creator?->name ?? 'ثبت نشده',
            'item_count' => (int) $order->items_count,
            'quantity' => (int) ($order->quantity_total ?? 0),
            'date' => $date ? Jalalian::fromDateTime($date)->format('Y/m/d') : '—',
            'related_number' => $order->invoice?->uuid,
            'sort_time' => $date?->timestamp ?? 0,
        ];
    }
}
