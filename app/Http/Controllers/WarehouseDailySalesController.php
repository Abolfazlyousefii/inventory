<?php

namespace App\Http\Controllers;

use App\Models\{Invoice, PreinvoiceOrder, Purchase, SalesReturnDocument, SalesReturnDocumentItem, StockCountDocument, WarehouseInboundReceipt, WarehouseInboundReceiptItem};
use App\Services\{SalesDocumentAccessService, WarehouseDailySalesService};
use App\Support\{JalaliDate, PageAccessCatalog};
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class WarehouseDailySalesController extends Controller
{
    public function index()
    {
        return view('warehouse-daily-sales.index');
    }

    private function day(Request $request): string
    {
        $request->validate(['day' => ['required', 'string', 'max:16']]);
        $day = JalaliDate::toGregorianDate($request->input('day'));
        if (! $day || $day > now()->toDateString()) {
            throw ValidationException::withMessages(['day' => 'تاریخ معتبر شمسی، حداکثر تا امروز را انتخاب کنید.']);
        }
        return $day;
    }

    private function filters(Request $request): array
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:100'], 'filter' => ['nullable', Rule::in(['all', 'pending', 'issues'])], 'page' => ['nullable', 'integer', 'min:1', 'max:10000']]);
        return [trim($data['q'] ?? ''), $data['filter'] ?? 'all'];
    }

    public function data(Request $request, WarehouseDailySalesService $service)
    {
        $day = $this->day($request);
        [$q, $filter] = $this->filters($request);
        $warehouse = $service->warehouseId();
        $summary = DB::query()->fromSub($service->rows($day, $warehouse), 'r')
            ->selectRaw('COUNT(*) total, COUNT(physical_quantity) reviewed, COALESCE(SUM(CASE WHEN physical_quantity <> stock_snapshot THEN 1 ELSE 0 END), 0) issues')->first();
        $rows = $service->rows($day, $warehouse, $q, $filter)->orderBy('name')->orderBy('k.product_id')->orderBy('k.variant_id')->paginate(40);
        return response()->json(['rows' => $rows->items(), 'summary' => $summary, 'page' => $rows->currentPage(),
            'last_page' => $rows->lastPage(), 'total' => $rows->total(), 'day' => JalaliDate::date($day), 'read_at' => JalaliDate::dateTime(now())]);
    }

    public function store(Request $request, WarehouseDailySalesService $service)
    {
        $day = $this->day($request);
        $data = $request->validate(['product_id' => ['required', 'integer', 'min:1'], 'variant_id' => ['required', 'integer', 'min:0'],
            'physical_quantity' => ['required', 'integer', 'min:0', 'max:1000000000'], 'expected_stock' => ['required', 'integer'],
            'version' => ['required', 'integer', 'min:0'], 'note' => ['nullable', 'string', 'max:2000']]);
        $saved = $service->save($day, $service->warehouseId(), $data, (int) $request->user()->id);
        return response()->json(['message' => 'شمارش و یادداشت ثبت شد؛ موجودی تغییر نکرد.', 'saved' => $saved]);
    }

    public function detail(Request $request, WarehouseDailySalesService $service)
    {
        $day = $this->day($request);
        $data = $request->validate(['product_id' => ['required', 'integer', 'min:1'], 'variant_id' => ['required', 'integer', 'min:0'],
            'type' => ['required', Rule::in(['incoming', 'outgoing', 'sales'])], 'page' => ['nullable', 'integer', 'min:1', 'max:10000']]);
        $variant = (int) $data['variant_id'];
        if ($data['type'] === 'sales') {
            $rows = $service->sales($day)->where('ii.product_id', $data['product_id'])->where('ii.variant_id', $variant ?: null)
                ->select('ii.quantity', 'i.uuid', 'i.id as invoice_id', 'i.created_at')->orderByDesc('i.created_at')->orderByDesc('ii.id')->paginate(20);
            $rows->getCollection()->transform(function ($row) use ($request) {
                $invoice = Invoice::find($row->invoice_id);
                return ['quantity' => (int) $row->quantity, 'label' => 'فاکتور '.$row->uuid, 'reason' => 'فروش ثبت‌شده؛ ممکن است رزرو آن در روز دیگری انجام شده باشد.',
                    'time' => JalaliDate::dateTime($row->created_at), 'actor' => null,
                    'url' => $invoice && PageAccessCatalog::userCanRoute($request->user(), 'invoices.show') && app(SalesDocumentAccessService::class)->canViewInvoiceReadonly($invoice, $request->user()) ? route('invoices.show', $invoice->uuid) : null];
            });
        } else {
            $rows = $service->movements($day, $service->warehouseId())->where('m.product_id', $data['product_id'])
                ->where('m.product_variant_id', $variant ?: null)->where('m.type', $data['type'] === 'incoming' ? 'in' : 'out')
                ->leftJoin('users as u', 'u.id', '=', 'm.user_id')->select('m.*', 'u.name as actor')->orderByDesc('m.created_at')->orderByDesc('m.id')->paginate(20);
            $rows->getCollection()->transform(fn ($row) => ['quantity' => (int) $row->quantity, 'label' => $row->reference ?: 'گردش #'.$row->id,
                'reason' => $row->note ?: $this->reasonLabel($row->reason), 'time' => JalaliDate::dateTime($row->created_at), 'actor' => $row->actor,
                'url' => $this->sourceUrl($row, $request)]);
        }
        return response()->json(['rows' => $rows->items(), 'page' => $rows->currentPage(), 'last_page' => $rows->lastPage(), 'total' => $rows->total()]);
    }

    private function reasonLabel(string $reason): string
    {
        return ['purchase' => 'خرید', 'sale' => 'فروش', 'return' => 'برگشت', 'sales_return' => 'برگشت از فروش', 'adjustment' => 'تعدیل موجودی',
            'transfer' => 'انتقال', 'purchase_item_added' => 'افزودن ردیف خرید', 'purchase_item_removed' => 'حذف ردیف خرید',
            'purchase_item_quantity_changed' => 'اصلاح تعداد خرید', 'invoice_cancel_return' => 'برگشت لغو فاکتور', 'invoice_adjustment_return' => 'برگشت اصلاح فاکتور'][$reason] ?? $reason;
    }

    private function sourceUrl(object $row, Request $request): ?string
    {
        $type = ltrim((string) $row->reference_type, '\\');
        $id = (int) $row->reference_id;
        $route = null; $parameter = null;
        if ($type === Purchase::class && $doc = Purchase::find($id)) { $route = 'purchases.show'; $parameter = $doc->id; }
        elseif ($type === Invoice::class && $doc = Invoice::find($id)) {
            if (! app(SalesDocumentAccessService::class)->canViewInvoiceReadonly($doc, $request->user())) return null;
            $route = 'invoices.show'; $parameter = $doc->uuid;
        }
        elseif ($type === WarehouseInboundReceiptItem::class && $doc = WarehouseInboundReceiptItem::find($id)) { $route = 'warehouse.inbound.show'; $parameter = $doc->receipt_id; }
        elseif ($type === WarehouseInboundReceipt::class && $doc = WarehouseInboundReceipt::find($id)) { $route = 'warehouse.inbound.show'; $parameter = $doc->id; }
        elseif ($type === SalesReturnDocument::class && $doc = SalesReturnDocument::find($id)) { $route = 'sales-returns.show'; $parameter = $doc->id; }
        elseif ($type === SalesReturnDocumentItem::class && $doc = SalesReturnDocumentItem::find($id)) { $route = 'sales-returns.show'; $parameter = $doc->document_id; }
        elseif (in_array($type, [StockCountDocument::class, 'stock_count_document'], true) && $doc = StockCountDocument::find($id)) { $route = 'stock-count-documents.show'; $parameter = $doc->id; }
        elseif ($type === PreinvoiceOrder::class && $doc = PreinvoiceOrder::find($id)) { $route = 'warehouse.reviews.show'; $parameter = $doc->uuid; }
        elseif (! $type && preg_match('/^PUR-(?:ADJ-)?(\d+)$/', (string) $row->reference, $m) && $doc = Purchase::find((int) $m[1])) { $route = 'purchases.show'; $parameter = $doc->id; }
        return $route && PageAccessCatalog::userCanRoute($request->user(), $route) ? route($route, $parameter) : null;
    }

    public function export(Request $request, WarehouseDailySalesService $service)
    {
        $day = $this->day($request); [$q, $filter] = $this->filters($request);
        $printing = $request->query('format') === 'print';
        $rows = $service->rows($day, $service->warehouseId(), $q, $printing ? $filter : 'issues')->orderBy('k.product_id')->orderBy('k.variant_id');
        if ($printing) return view('warehouse-daily-sales.print', ['rows' => $rows->cursor(), 'day' => JalaliDate::date($day)]);
        return response()->streamDownload(function () use ($rows, $day) {
            $file = fopen('php://output', 'w'); fwrite($file, "\xEF\xBB\xBF");
            fputcsv($file, ['روز', 'کالا', 'تنوع', 'کد', 'موجودی هنگام شمارش', 'شمارش', 'اختلاف', 'موجودی فعلی', 'تغییر پس از شمارش', 'یادداشت', 'ثبت‌کننده', 'زمان ثبت']);
            foreach ($rows->cursor() as $r) {
                $cells = [JalaliDate::date($day), $r->name, $r->variant_name, $r->code, $r->stock_snapshot, $r->physical_quantity,
                    $r->physical_quantity - $r->stock_snapshot, $r->current_stock, $r->current_stock === null || (int) $r->current_stock !== (int) $r->stock_snapshot ? 'نیازمند بازبینی' : '', $r->note, $r->counted_by, JalaliDate::dateTime($r->counted_at)];
                // Prevent spreadsheet formulas in externally entered names/notes.
                fputcsv($file, array_map(fn ($c) => is_string($c) && preg_match('/^[\s]*[=+@-]/u', $c) ? "'".$c : $c, $cells));
            }
            fclose($file);
        }, 'warehouse-daily-discrepancies-'.$day.'.csv', ['Content-Type' => 'text/csv; charset=UTF-8']);
    }
}
