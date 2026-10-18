<?php
namespace App\Http\Controllers;

use App\Models\{Product, StockMovement, Invoice, Purchase};
use Illuminate\Http\Request;

class ProductStockLedgerController extends Controller
{
    public function index(Request $request, Product $product)
    {
        $data=$request->validate([
            'variant_id'=>['nullable','integer','min:1'], 'q'=>['nullable','string','max:150'],
            'effect'=>['nullable','in:recorded,reference,incomplete'], 'warehouse_id'=>['nullable','integer','min:1'],
        ]);
        $filters=['q'=>$data['q'] ?? '', 'effect'=>$data['effect'] ?? '', 'warehouse_id'=>$data['warehouse_id'] ?? ''];
        $selectedVariant=!empty($data['variant_id'])?$product->variants()->whereKey($data['variant_id'])->firstOrFail():null;
        $history=app(\App\Services\ProductStockHistoryService::class)->history($product->id,$selectedVariant?->id);
        $all=$history['rows'];
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
        $warehouses=\Illuminate\Support\Facades\DB::table('warehouses')->orderBy('id')->get(['id','name']);
        return view('products.stock-ledger',compact('product','selectedVariant','movements','summary','sources','filters','warehouses'));
    }
}
