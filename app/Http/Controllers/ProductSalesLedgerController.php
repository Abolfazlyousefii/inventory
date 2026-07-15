<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductSalesLedgerController extends Controller
{
    public function index(Request $request, Product $product)
    {
        $variantId = $request->filled('variant_id') ? (int) $request->input('variant_id') : null;
        $selectedVariant = null;

        if ($variantId) {
            $selectedVariant = $product->variants()->whereKey($variantId)->firstOrFail();
        }

        $query = InvoiceItem::query()
            ->select('invoice_items.*')
            ->join('invoices', 'invoices.id', '=', 'invoice_items.invoice_id')
            ->where('invoice_items.product_id', $product->id)
            ->where('invoices.status', '!=', Invoice::STATUS_NOT_SHIPPED)
            ->with([
                'product:id,name',
                'variant:id,variant_name',
                'invoice:id,uuid,customer_id,customer_name,created_at,document_date,preinvoice_order_id',
                'invoice.preinvoiceOrder:id,created_by',
                'invoice.preinvoiceOrder.creator:id,name',
            ]);

        if ($selectedVariant) {
            $query->where('invoice_items.variant_id', $selectedVariant->id);
        } elseif ($request->boolean('only_without_variant')) {
            $query->whereNull('invoice_items.variant_id');
        }

        if ($request->filled('date_from')) {
            $from = Carbon::parse($request->input('date_from'))->startOfDay();
            $query->where('invoices.created_at', '>=', $from);
        }

        if ($request->filled('date_to')) {
            $to = Carbon::parse($request->input('date_to'))->endOfDay();
            $query->where('invoices.created_at', '<=', $to);
        }

        if ($request->filled('customer_id')) {
            $query->where('invoices.customer_id', (int) $request->input('customer_id'));
        }

        if ($request->filled('invoice_uuid')) {
            $invoiceUuid = trim((string) $request->input('invoice_uuid'));
            $query->where('invoices.uuid', 'like', "%{$invoiceUuid}%");
        }

        if ($request->filled('creator_id')) {
            $creatorId = (int) $request->input('creator_id');
            $query->whereHas('invoice.preinvoiceOrder', function ($q) use ($creatorId) {
                $q->where('created_by', $creatorId);
            });
        }

        $summary = (clone $query)
            ->select([])
            ->selectRaw('COALESCE(SUM(invoice_items.quantity), 0) as total_quantity')
            ->selectRaw('COALESCE(SUM(invoice_items.line_total), 0) as total_amount')
            ->first();

        $query->orderByDesc('invoices.created_at')->orderByDesc('invoice_items.id');

        if ($request->input('export') === 'csv') {
            return $this->exportCsv((clone $query)->get(), $product, $selectedVariant);
        }

        $ledgerItems = $query->paginate(25)->withQueryString();

        $customers = Customer::query()
            ->orderBy('first_name')
            ->orderBy('last_name')
            ->get(['id', 'first_name', 'last_name']);

        $creators = User::query()->orderBy('name')->get(['id', 'name']);
        $variants = $product->variants()->orderBy('variant_code')->get(['id', 'variant_name', 'variant_code']);

        return view('products.sales-ledger', compact(
            'product',
            'variants',
            'selectedVariant',
            'ledgerItems',
            'customers',
            'creators',
            'summary'
        ));
    }

    private function exportCsv($items, Product $product, ?ProductVariant $variant): StreamedResponse
    {
        $filename = 'sales-ledger-' . $product->id . '-' . now()->format('Ymd-His') . '.csv';

        return response()->streamDownload(function () use ($items, $product, $variant) {
            $handle = fopen('php://output', 'w');
            fwrite($handle, "\xEF\xBB\xBF");

            fputcsv($handle, [
                'sale_date',
                'invoice_number',
                'customer_name',
                'product_name',
                'variant_name',
                'quantity',
                'unit_price',
                'line_total',
                'creator',
            ]);

            foreach ($items as $item) {
                fputcsv($handle, [
                    optional($item->invoice?->created_at)->format('Y-m-d H:i:s'),
                    $item->invoice?->uuid ?? '',
                    $item->invoice?->customer_name ?? '—',
                    $product->name,
                    $item->variant?->variant_name ?? ($variant?->variant_name ?? '—'),
                    (int) $item->quantity,
                    (int) $item->price,
                    (int) $item->line_total,
                    $item->invoice?->preinvoiceOrder?->creator?->name ?? '—',
                ]);
            }

            fclose($handle);
        }, $filename, ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
