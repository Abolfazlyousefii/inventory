<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Services\SalesHavalehService;
use App\Services\SalesHavalehStatusService;
use Illuminate\Http\Request;

class SalesHavalehController extends Controller
{
    public function __construct(
        private readonly SalesHavalehService $service,
        private readonly SalesHavalehStatusService $statusService,
    ) {}

    public function createFromFinancial(int $financialId)
    {
        $invoice = $this->service->createFromFinancialRecord($financialId, auth()->id());

        return response()->json([
            'message' => 'حواله فروش با موفقیت ایجاد شد.',
            'invoice_id' => $invoice->id,
            'invoice_uuid' => $invoice->uuid,
            'status' => $invoice->status,
        ], 201);
    }

    public function show(Invoice $invoice)
    {
        $invoice->load(['items.product', 'items.variant', 'histories.actor']);

        return response()->json([
            'id' => $invoice->id,
            'uuid' => $invoice->uuid,
            'status' => $invoice->status,
            'status_label' => $this->statusService->labels()[$invoice->status] ?? $invoice->status,
            'total' => (int) $invoice->total,
            'items' => $invoice->items,
            'history' => $invoice->histories,
        ]);
    }

    public function view(Invoice $invoice)
    {
        $invoice->load(['items.product', 'items.variant']);

        return response()->json([
            'uuid' => $invoice->uuid,
            'customer' => [
                'id' => $invoice->customer_id,
                'name' => $invoice->customer_name,
                'mobile' => $invoice->customer_mobile,
            ],
            'status' => [
                'key' => $invoice->status,
                'label' => $this->statusService->labels()[$invoice->status] ?? $invoice->status,
            ],
            'items' => $invoice->items->map(fn ($item) => [
                'product' => $item->product?->name,
                'variant' => $item->variant?->variant_name,
                'quantity' => (int) $item->quantity,
                'price' => (int) $item->price,
                'line_total' => (int) $item->line_total,
            ]),
            'total' => (int) $invoice->total,
        ]);
    }

    public function update(Invoice $invoice, Request $request)
    {
        $data = $request->validate([
            'items' => 'required|array|min:1',
            'items.*.id' => 'required|exists:invoice_items,id',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.price' => 'required|integer|min:0',
        ]);

        $updated = $this->service->updateItems($invoice, $data['items'], (int) auth()->id(), $data['edit_reason'] ?? 'other', $data['edit_reason'] ?? null);

        return response()->json([
            'message' => 'حواله فروش بروزرسانی شد.',
            'invoice_id' => $updated->id,
            'total' => (int) $updated->total,
        ]);
    }

    public function patchStatus(Invoice $invoice, Request $request)
    {
        $data = $request->validate([
            'status' => 'required|string',
            'note' => 'nullable|string|max:1000',
        ]);

        $updated = $this->service->changeStatus($invoice, $data['status'], $data['note'] ?? null, auth()->id());

        return response()->json([
            'message' => 'وضعیت حواله فروش بروزرسانی شد.',
            'status' => $updated->status,
            'status_label' => $this->statusService->labels()[$updated->status] ?? $updated->status,
        ]);
    }

    public function history(Invoice $invoice)
    {
        $invoice->load('histories.actor');

        return response()->json([
            'invoice_id' => $invoice->id,
            'history' => $invoice->histories,
        ]);
    }
}
