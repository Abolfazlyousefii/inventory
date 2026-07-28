<?php

namespace App\Http\Controllers\External;

use App\Http\Controllers\Controller;
use App\Services\ExternalPaidOrderService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class RegisterPaidStoreOrderController extends Controller
{
    public function __invoke(Request $request, ExternalPaidOrderService $service): JsonResponse
    {
        $this->authorizeRequest($request);

        $payload = $request->validate([
            'event' => ['required', 'string', 'in:order.paid'],
            'crm_order_id' => ['required', 'integer', 'min:1'],
            'occurred_at' => ['nullable', 'date'],
            'user' => ['nullable', 'array'],
            'customer' => ['nullable', 'array'],
            'address' => ['nullable', 'array'],
            'shipping_address' => ['nullable', 'array'],
            'order' => ['required', 'array'],
            'items' => ['required', 'array', 'min:1', 'max:500'],
            'items.*' => ['required', 'array'],
            'items.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
            'items.*.price' => ['required', 'integer', 'min:1'],
            'items.*.line_total' => ['nullable', 'integer', 'min:0'],
            'items.*.discount_amount' => ['nullable', 'integer', 'min:0'],
            'items.*.product' => ['nullable', 'array'],
            'items.*.price_variant' => ['required', 'array'],
        ]);

        $result = $service->import($payload);
        $invoice = $result['invoice'];
        $created = $result['created'];

        return response()->json([
            'message' => $created ? 'Paid order registered for collection.' : 'Paid order was already registered.',
            'created' => $created,
            'invoice_id' => $invoice->id,
            'invoice_number' => $invoice->uuid,
            'crm_order_id' => (string) $invoice->external_order_id,
            'status' => $invoice->status,
        ], $created ? 201 : 200);
    }

    private function authorizeRequest(Request $request): void
    {
        $expectedToken = (string) config('services.external_sync.token');
        $providedToken = (string) ($request->header('X-CRM-Token') ?: $request->bearerToken());

        abort_if($expectedToken === '' || ! hash_equals($expectedToken, $providedToken), 401, 'Unauthenticated.');
    }
}
