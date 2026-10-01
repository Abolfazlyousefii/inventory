<?php

namespace App\Http\Controllers;

use App\Models\PreinvoiceOrder;
use App\Models\ProductVariant;
use App\Models\PreinvoiceDraftReservation;
use App\Services\SalesDocumentAccessService;
use App\Services\PreinvoiceReservationService;
use App\Services\PreinvoiceDraftReservationService;
use App\Services\PreinvoiceDiscountService;
use App\Services\PreinvoiceDiscountHydrator;
use App\Support\ActivityLogger;
use App\Support\ReservationSideEffects;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class CollaborativePreinvoiceController extends Controller
{
    private function order(string $uuid): PreinvoiceOrder
    {
        return PreinvoiceOrder::where('uuid', $uuid)->with('invoice')->firstOrFail();
    }

    private function authorizeOrder(PreinvoiceOrder $order): bool
    {
        $access = app(SalesDocumentAccessService::class);
        $finance = app(PreinvoiceController::class)->canFinanceEditPreinvoice($order, auth()->user());
        abort_unless($finance || $access->canSellerEditPreinvoiceItems($order, auth()->user()), 403);
        return $finance;
    }

    private function document(PreinvoiceOrder $order): array
    {
        $order->load('items.product', 'items.variant');
        return ['uuid' => $order->uuid, 'status' => $order->status, 'total' => (int) $order->total_price,
                'items' => $order->items->map(fn ($item) => [
                    'id' => $item->id, 'variant_id' => $item->variant_id, 'product_id' => $item->product_id,
                    'name' => ($item->product?->name ?? '').' / '.($item->variant?->variant_name ?? ''),
                    'quantity' => (int) $item->quantity, 'price' => (int) $item->price,
                    'line_discount_amount' => (int) $item->line_discount_amount,
                ])->values()->all()];
    }

    public function show(string $uuid)
    {
        $order = $this->order($uuid);
        if ($order->invoice) {
            abort_unless(app(SalesDocumentAccessService::class)->canSellerEditInvoiceItems($order->invoice, auth()->user()), 403);
            return redirect()->route('preinvoice.my.invoice-correction.edit', $order->invoice->uuid);
        }
        $this->authorizeOrder($order);
        return view('preinvoice.collaborative', ['order' => $order]);
    }

    public function state(string $uuid)
    {
        $order = $this->order($uuid);
        if ($order->invoice) {
            abort_unless(app(SalesDocumentAccessService::class)->canViewInvoiceReadonly($order->invoice, auth()->user()), 403);
            return response()->json(['converted' => true, 'url' => route('preinvoice.my.invoice-correction.edit', $order->invoice->uuid)]);
        }
        $this->authorizeOrder($order);
        return response()->json(['document' => $this->document($order)]);
    }

    public function search(string $uuid, Request $request)
    {
        $this->authorizeOrder($this->order($uuid));
        $query = trim((string) $request->query('q', ''));
        if (mb_strlen($query) < 2) return response()->json(['items' => []]);
        $variants = ProductVariant::with('product:id,name')->where('is_active', true)
            ->where(function ($builder) use ($query) {
                $builder->where('variant_name', 'like', '%'.$query.'%')
                    ->orWhereHas('product', fn ($p) => $p->where('name', 'like', '%'.$query.'%'));
            })->orderBy('id')->limit(30)->get();
        return response()->json(['items' => $variants->map(fn ($variant) => [
            'id' => $variant->id, 'name' => ($variant->product?->name ?? '').' / '.$variant->variant_name,
            'price' => (int) $variant->sell_price, 'stock' => (int) $variant->stock,
        ])]);
    }

    public function change(string $uuid, Request $request)
    {
        $data = $request->validate([
            'action' => 'required|in:add,set,remove', 'item_id' => 'nullable|integer',
            'variant_id' => 'nullable|integer', 'field' => 'nullable|in:quantity,price',
            'value' => 'nullable|integer|min:0|max:1000000000000',
            'expected' => 'nullable|integer|min:0', 'expected_quantity' => 'nullable|integer|min:0',
            'expected_price' => 'nullable|integer|min:0', 'reason' => 'required|string|min:3|max:1000',
        ]);
        $result = ReservationSideEffects::transaction(function () use ($uuid, $data) {
            auth()->user()->newQuery()->whereKey(auth()->id())->lockForUpdate()->firstOrFail();
            $order = PreinvoiceOrder::where('uuid', $uuid)->lockForUpdate()->with('invoice')->firstOrFail();
            if ($order->invoice) {
                abort_unless(app(SalesDocumentAccessService::class)->canViewInvoiceReadonly($order->invoice, auth()->user()), 403);
                return ['converted' => true, 'url' => route('preinvoice.my.invoice-correction.edit', $order->invoice->uuid)];
            }
            $finance = $this->authorizeOrder($order);
            if ($finance) app(PreinvoiceReservationService::class)->assertFinanceApprovable($order, auth()->user(), true);
            $items = $order->items()->lockForUpdate()->get();
            $before = $items->map->getAttributes()->all();
            $discountState = app(PreinvoiceDiscountHydrator::class)->hydrateForEditing($order);
            $item = $items->firstWhere('id', (int) ($data['item_id'] ?? 0));
            if ($data['action'] === 'add') {
                $variant = ProductVariant::whereKey((int) ($data['variant_id'] ?? 0))->where('is_active', true)->lockForUpdate()->firstOrFail();
                if ($items->contains('variant_id', $variant->id)) return ['conflict' => 'این کالا قبلاً اضافه شده است.', 'document' => $this->document($order)];
                if ((int) $variant->sell_price <= 0) throw ValidationException::withMessages(['price' => 'قیمت فروش کالا را ابتدا تعیین کنید.']);
                $item = $order->items()->create(['product_id' => $variant->product_id, 'variant_id' => $variant->id,
                                                 'quantity' => 1, 'price' => (int) $variant->sell_price, 'line_total' => (int) $variant->sell_price,
                                                 'line_discount_amount' => 0, 'sort_order' => ((int) $items->max('sort_order')) + 1]);
                if ($finance) app(PreinvoiceReservationService::class)->adjustOfficialReservationDelta($order, $item, 1, auth()->user());
            } elseif ($data['action'] === 'remove') {
                if (!$item) return ['document' => $this->document($order)];
                if (!isset($data['expected_quantity'], $data['expected_price']) || (int) $item->quantity !== $data['expected_quantity'] || (int) $item->price !== $data['expected_price']) {
                    return ['conflict' => 'این ردیف در تب دیگری تغییر کرده است. پیش از حذف بررسی کنید.', 'document' => $this->document($order)];
                }
                if ($items->count() <= 1) throw ValidationException::withMessages(['items' => 'حداقل یک قلم باید در سند باقی بماند.']);
                if ($finance) app(PreinvoiceReservationService::class)->adjustOfficialReservationDelta($order, $item, -(int) $item->quantity, auth()->user());
                $item->delete();
            } else {
                if (!$item) return ['conflict' => 'این ردیف در تب دیگری حذف شده است.', 'document' => $this->document($order)];
                $field = $data['field'] ?? null;
                if (!$field || !isset($data['value'], $data['expected'])) throw ValidationException::withMessages(['items' => 'تغییر ارسالی ناقص است.']);
                if ($field === 'quantity' && ($data['value'] < 1 || $data['value'] > 1000000)) throw ValidationException::withMessages(['quantity' => 'تعداد باید بین ۱ و ۱۰۰۰۰۰۰ باشد.']);
                if ($field === 'price' && $data['value'] < 1) throw ValidationException::withMessages(['price' => 'قیمت باید بیشتر از صفر باشد.']);
                if ((int) $item->{$field} !== $data['expected'] && (int) $item->{$field} !== $data['value']) {
                    return ['conflict' => 'این مقدار در تب دیگری تغییر کرده است.', 'document' => $this->document($order)];
                }
                if ($field === 'quantity' && $finance) app(PreinvoiceReservationService::class)->adjustOfficialReservationDelta($order, $item, $data['value'] - (int) $item->quantity, auth()->user());
                $item->update([$field => $data['value']]);
            }
            if (!$finance) {
                $hasOfficial = PreinvoiceDraftReservation::where('preinvoice_order_id', $order->id)->where('reservation_scope', 'official')->whereNull('released_at')->whereNull('release_reason')->exists();
                if ($hasOfficial) throw ValidationException::withMessages(['items' => 'رزرو رسمی این سند باید پیش از ویرایش بررسی شود.']);
                if ($order->is_auto_draft && $order->draft_token) {
                    // Collaborative draft edits persist selections only. If an older client
                    // left a temporary hold behind, unwind it through the release service.
                    app(PreinvoiceDraftReservationService::class)->releaseTokenReservations(
                        (string) $order->draft_token,
                        (int) $order->created_by,
                        'draft_no_reservation',
                        'ویرایش همزمان پیش‌نویس بدون رزرو موجودی انجام شد؛ رزرو موقت قدیمی آزاد شد.'
                    );
                }
            }
            // Preserve the existing discount contract; recalculate allocations against new items.
            $order->load('items.product', 'items.variant');
            app(PreinvoiceDiscountService::class)->applyToOrder($order, [
                'invoice_discount_type' => $discountState['invoice_discount']['type'] ?? $order->invoice_discount_type,
                'invoice_discount_value' => $discountState['invoice_discount']['value'] ?? $order->invoice_discount_value,
                'discount_breakdown' => ['groups' => $discountState['product_groups'] ?? []],
            ]);
            if ($order->is_auto_draft) $order->update(['auto_saved_at' => now()]);
            ActivityLogger::log('preinvoice_collaborative_item_edit', $order, $data['reason'], ['before' => $before, 'after' => $order->items()->get()->map->getAttributes()->all()]);
            return ['document' => $this->document($order->fresh())];
        });
        return response()->json($result);
    }
}
