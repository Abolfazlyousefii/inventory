<?php
namespace App\Http\Controllers;

use App\Models\{Product, ProductVariant, StockMovement, Invoice, Purchase};
use App\Services\AutomaticBundleAvailabilityService;
use App\Support\AutomaticBundlePlan;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\Request;

class ProductStockLedgerController extends Controller
{
    public function index(Request $request, Product $product)
    {
        $data=$request->validate([
            'variant_id'=>['nullable','integer','min:1'], 'q'=>['nullable','string','max:150'],
            'effect'=>['nullable','in:recorded,reference,incomplete'], 'warehouse_id'=>['nullable','integer','min:1'],
            'category'=>['nullable','in:all,purchases,sales,returns,transfers,stocktakes,reservations,other'],
        ]);
        $filters=['q'=>$data['q'] ?? '', 'effect'=>$data['effect'] ?? '', 'warehouse_id'=>$data['warehouse_id'] ?? '', 'category'=>$data['category'] ?? ''];
        $selectedVariant=!empty($data['variant_id'])?$product->variants()->whereKey($data['variant_id'])->firstOrFail():null;
        $history=app(\App\Services\ProductStockHistoryService::class)->history($product->id,$selectedVariant?->id);
        $all=$history['rows'];
        // Tag rows before filtering; summary and detail links share exactly the same scope.
        $overviewService=app(\App\Services\ProductStockOverviewService::class);
        $summary=(object)[
            'records'=>$all->count(), 'sold'=>$history['sold'], 'current'=>$history['current'],
            'cached_current'=>$history['cached_current'], 'reserved'=>$history['reserved'],
            'projection_matches'=>$history['projection_matches'], 'incomplete_records'=>$history['incomplete_records'],
        ];
        $term=\App\Support\ProductFinderSearchNormalizer::normalize($filters['q']);
        $all=$all->filter(function ($row) use ($filters,$term) {
            if ($filters['effect'] !== '' && $row->evidence_level !== $filters['effect']) return false;
            if ($filters['warehouse_id'] !== '' && (int)$row->warehouse_id !== (int)$filters['warehouse_id']) return false;
            if ($term === '') return true;
            $text=implode(' ',array_filter([
                $row->variant?->variant_name, $row->variant?->variant_code, $row->variant_snapshot ?? null,
                $row->variant_code_snapshot ?? null, $row->reference, $row->related_reference ?? null,
                $row->note, $row->reason, $row->history_kind, $row->warehouse?->name, $row->user?->name,
            ]));
            return str_contains(\App\Support\ProductFinderSearchNormalizer::normalize($text),$term);
        })->values();
        $overview=$overviewService->summarize($all,$product->id,$selectedVariant?->id);
        if ($filters['category'] !== '' && $filters['category'] !== 'all') {
            $all=$all->where('overview_category',$filters['category'])->values();
        }
        // One visible event per receipt. Its supporting result is displayed inside
        // the numeric movement; retain an orphan/zero receipt as its own row.
        $visibleIds=$all->pluck('id')->map(fn ($id)=>(string)$id)->all();
        $all=$all->reject(fn ($row)=>isset($row->linked_history_id)
            && in_array((string)$row->linked_history_id,$visibleIds,true))->values();
        $page=\Illuminate\Pagination\LengthAwarePaginator::resolveCurrentPage();
        $movements=new \Illuminate\Pagination\LengthAwarePaginator($all->forPage($page,30)->values(),$all->count(),30,$page,['path'=>$request->url()]);
        $movements->appends(array_filter($data,fn($value)=>$value!==null && $value!==''));
        $purchaseIds=[];$invoiceIds=[];
        foreach($movements as $row){if($row->reference_type===Purchase::class&&$row->reference_id)$purchaseIds[$row->id]=(int)$row->reference_id;elseif(str_starts_with((string)$row->reason,'purchase')&&preg_match('/^PUR-(\d+)$/',(string)$row->reference,$m))$purchaseIds[$row->id]=(int)$m[1];if($row->reference_type===Invoice::class&&$row->reference_id)$invoiceIds[$row->id]=(int)$row->reference_id;}
        $purchases=Purchase::whereIn('id',array_values($purchaseIds))->pluck('id','id');$invoices=Invoice::whereIn('id',array_values($invoiceIds))->pluck('uuid','id');$sources=[];
        foreach($movements as $row){$url=null;$label=$row->reference ?: ($row->reference_id ? 'سند #'.$row->reference_id : '—');if(isset($purchaseIds[$row->id])&&$purchases->has($purchaseIds[$row->id])){$url=route('purchases.show',$purchaseIds[$row->id]);$label='خرید #'.$purchaseIds[$row->id];}elseif(isset($invoiceIds[$row->id])&&$invoices->has($invoiceIds[$row->id])){$label='فاکتور '.$invoices[$invoiceIds[$row->id]];$url=route('invoices.show',$invoices[$invoiceIds[$row->id]]);}$sources[$row->id]=['label'=>$label,'url'=>$url];}
        $countRows=$movements->getCollection()->filter(fn($row)=>in_array($row->reference_type,['stock_count_document',\App\Models\StockCountDocument::class],true)&&$row->reference_id);
        if($countRows->isNotEmpty()){
            $counts=\App\Models\StockCountDocument::whereIn('id',$countRows->pluck('reference_id'))->pluck('document_number','id');
            foreach($countRows as $row)if($counts->has($row->reference_id))$sources[$row->id]=['label'=>$counts[$row->reference_id],'url'=>route('stock-count-documents.show',$row->reference_id)];
        }
        $transferRows=$movements->getCollection()->filter(fn($row)=>$row->reference_type===\App\Models\WarehouseTransfer::class&&$row->reference_id);
        if($transferRows->isNotEmpty()){
            $transfers=\App\Models\WarehouseTransfer::whereIn('id',$transferRows->pluck('reference_id'))->pluck('id','id');
            foreach($transferRows as $row)if($transfers->has($row->reference_id))$sources[$row->id]=['label'=>$row->reference ?: 'حواله #'.$row->reference_id,'url'=>route('vouchers.show',$row->reference_id)];
        }
        $receiptRows=$movements->getCollection()->filter(fn($row)=>$row->reference_type===\App\Models\WarehouseInboundReceiptItem::class && $row->reference_id);
        if($receiptRows->isNotEmpty()){
            $receipts=\App\Models\WarehouseInboundReceiptItem::whereIn('id',$receiptRows->pluck('reference_id'))->with('receipt:id,receipt_number')->get()->keyBy('id');
            foreach($receiptRows as $row) if($receipts->get($row->reference_id)?->receipt) {
                $receipt=$receipts[$row->reference_id]->receipt;
                $sources[$row->id]=['label'=>$receipt->receipt_number,'url'=>route('warehouse.inbound.show',$receipt->id)];
            }
        }
        $returnRows=$movements->getCollection()->filter(fn($row)=>$row->reference_type===\App\Models\PurchaseReturn::class&&$row->reference_id);
        if($returnRows->isNotEmpty()){
            $returns=\App\Models\PurchaseReturn::withTrashed()->whereIn('id',$returnRows->pluck('reference_id'))->pluck('document_number','id');
            foreach($returnRows as $row)if($returns->has($row->reference_id))$sources[$row->id]=['label'=>'برگشت از خرید '.$returns[$row->reference_id],'url'=>route('purchase-returns.show',$row->reference_id)];
        }
        foreach($movements as $row) if(!($row instanceof StockMovement)) $sources[$row->id]=['label'=>$row->reference,'url'=>$row->source_url];
        foreach($movements as $row) if($row->source_url) $sources[$row->id]=['label'=>$row->source_label ?? $row->reference,'url'=>$row->source_url];
        $warehouses=DB::table('warehouses')->orderBy('id')->get(['id','name']);
        // Automatic bundles hold protected components, not finished warehouse units.
        // Keep their audit events separate from physical stock movements to avoid
        // presenting a pause/resume or component allocation as newly created stock.
        $bundleLedger = $product->is_bundle && $product->bundle_stock_mode === 'automatic'
            ? $this->automaticBundleLedger($product, $selectedVariant) : null;
        return view('products.stock-ledger',compact('product','selectedVariant','movements','summary','sources','filters','warehouses','overview','bundleLedger'));
    }

    private function automaticBundleLedger(Product $product, ?ProductVariant $selectedVariant): array
    {
        $ids = $selectedVariant ? collect([(int) $selectedVariant->id]) : $product->variants()->pluck('id');
        $recipes = DB::table('product_bundle_components')->whereIn('bundle_variant_id', $ids)
            ->get(['bundle_variant_id', 'component_variant_id', 'quantity'])->groupBy('bundle_variant_id');
        $allocations = DB::table('bundle_component_allocations')->whereIn('bundle_variant_id', $ids)
            ->get(['bundle_variant_id', 'component_variant_id', 'remaining'])->groupBy('bundle_variant_id');
        $protectedPacks = 0;
        $availableForNewOrders = 0;
        foreach ($ids as $id) {
            $recipe = ($recipes->get($id) ?? collect())->pluck('quantity', 'component_variant_id')
                ->mapWithKeys(fn ($quantity, $partId) => [(int) $partId => (int) $quantity])->all();
            $remaining = ($allocations->get($id) ?? collect())->pluck('remaining', 'component_variant_id')
                ->mapWithKeys(fn ($quantity, $partId) => [(int) $partId => (int) $quantity])->all();
            $protectedPacks += AutomaticBundlePlan::available($recipe, $remaining);
            $availableForNewOrders += app(AutomaticBundleAvailabilityService::class)->available((int) $id);
        }
        $events = DB::table('bundle_management_events as e')
            ->leftJoin('users as u', 'u.id', '=', 'e.actor_id')
            ->whereIn('e.bundle_variant_id', $ids)
            ->orderByDesc('e.created_at')->orderByDesc('e.id')
            ->paginate(15, ['e.id','e.event_id','e.action','e.reason','e.details','e.created_at', 'u.name as actor_name'], 'bundle_events_page')
            ->withQueryString();
        // One lookup for all variants referenced by the visible audit page.
        $decoded = $events->getCollection()->mapWithKeys(fn ($event) => [$event->id => json_decode($event->details, true) ?: []]);
        $partIds = $decoded->flatMap(function ($detail) {
            return collect($detail['rows'] ?? [])->take(25)->map(fn ($row) => (int) ($row['variant_id'] ?? $row[0] ?? 0));
        })->filter()->unique()->values();
        $parts = DB::table('product_variants as v')->join('products as p', 'p.id', '=', 'v.product_id')
            ->whereIn('v.id', $partIds)->get(['v.id','v.product_id','v.variant_name','v.variant_code','p.name as product_name'])->keyBy('id');
        $labels = [
            'allocation_adjust' => 'تغییر سهمیه قطعات بسته', 'release' => 'آزادسازی قطعات بسته',
            'pause' => 'توقف فروش بسته', 'resume' => 'ادامه فروش بسته',
            'archive' => 'بایگانی بسته', 'restore' => 'بازگردانی از بایگانی',
        ];
        $events->getCollection()->transform(function ($event) use ($decoded, $parts, $labels) {
            $event->label = $labels[$event->action] ?? $event->action;
            $event->changes = [];
            $event->returned_units = 0;
            $event->allocated_units = 0;
            $event->omitted_changes = 0;
            foreach (($decoded[$event->id]['rows'] ?? []) as $row) {
                $id = (int) ($row['variant_id'] ?? $row[0] ?? 0);
                $delta = $event->action === 'release'
                    ? (int) ($row[5] ?? 0)
                    : ($event->action === 'allocation_adjust' ? (int) ($row['ordinary_stock_delta'] ?? 0) : 0);
                if ($id < 1 || $delta === 0) continue;
                $event->returned_units += max(0, $delta);
                $event->allocated_units += max(0, -$delta);
                if (count($event->changes) >= 25) {
                    $event->omitted_changes++;
                    continue;
                }
                $part = $parts->get($id);
                $event->changes[] = [
                    'name' => $part ? $part->product_name.' / '.($part->variant_name ?: $part->variant_code ?: $id) : 'تنوع #'.$id,
                    'product_id' => $part?->product_id, 'variant_id' => $id, 'ordinary_delta' => $delta,
                ];
            }
            return $event;
        });
        return [
            'protected_packs' => $protectedPacks,
            'available_for_new_orders' => $availableForNewOrders,
            'protected_units' => $allocations->flatten(1)->sum(fn ($row) => (int) $row->remaining),
            'events' => $events,
        ];
    }
}
