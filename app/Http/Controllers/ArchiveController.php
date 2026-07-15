<?php

namespace App\Http\Controllers;

use App\Models\Invoice;
use App\Models\PreinvoiceOrder;
use App\Services\SalesPrintDocumentService;
use Illuminate\Http\Request;

class ArchiveController extends Controller
{
    public function showPreinvoice(string $uuid, Request $request, SalesPrintDocumentService $printService)
    {
        $order = PreinvoiceOrder::query()
            ->with([
                'items.product',
                'items.variant.modelList',
                'items.variant.color',
                'creator:id,name',
                'warehouseReviewer:id,name',
                'shippingMethod:id,name,price',
                'reviews.user:id,name',
                'activityLogs.user:id,name',
                'invoice:id,uuid,preinvoice_order_id,status,created_at,document_date',
            ])
            ->where('uuid', $uuid)
            ->firstOrFail();

        if ($request->routeIs('preinvoice.print') || $request->has('print') || $request->has('mode')) {
            $printData = $printService->preinvoiceData($order, (string) $request->query('mode', $request->query('print', 'warehouse')));

            return view('prints.invoice', compact('printData'));
        }

        if ($order->invoice?->uuid) {
            return redirect()
                ->route('invoices.show', $order->invoice->uuid)
                ->with('warning', 'صفحه آرشیو پیش‌فاکتور در نسخه جدید حذف شده است. اطلاعات این سند از صفحه فاکتور قابل مشاهده است.');
        }

        return redirect()
            ->route('preinvoice.my.index')
            ->with('warning', 'صفحه آرشیو پیش‌فاکتور در نسخه جدید غیرفعال است.');
    }

    public function showInvoice(string $uuid, Request $request, SalesPrintDocumentService $printService)
    {
        $invoice = Invoice::query()
            ->with([
                'items.product',
                'items.variant.modelList',
                'items.variant.color',
                'payments.creator:id,name',
                'payments.cheque',
                'notes.user:id,name',
                'histories.actor:id,name',
                'activityLogs.user:id,name',
                'preinvoiceOrder:id,uuid,status,created_at,document_date',
            ])
            ->where('uuid', $uuid)
            ->firstOrFail();

        if ($request->has('print') || $request->has('mode')) {
            $printData = $printService->invoiceData($invoice, (string) $request->query('mode', $request->query('print', 'warehouse')));

            return view('prints.invoice', compact('printData'));
        }

        return view('archive.invoice-show', compact('invoice'));
    }
}
