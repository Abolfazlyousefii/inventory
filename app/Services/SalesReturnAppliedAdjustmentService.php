<?php

namespace App\Services;

use App\Models\{ActivityLog, Customer, CustomerLedger, SalesReturnDocument, SalesReturnDocumentItem, SalesReturnDocumentRevision, StockMovement, WarehouseStock};
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class SalesReturnAppliedAdjustmentService
{
    public function __construct(private SalesReturnService $salesReturns) {}

    public function updateApplied(SalesReturnDocument $document, array $data, int $actorId, string $reason): SalesReturnDocument
    {
        return DB::transaction(function () use ($document, $data, $actorId, $reason) {
            $doc = $this->lockedApplied($document->id);
            $doc->load(['items.destinationWarehouse','items.product','items.variant','customer']);
            Customer::whereKey($doc->customer_id)->lockForUpdate()->firstOrFail();
            $before = $this->snapshot($doc);
            $revision = $this->revision($doc, 'applied_updated', $reason, $before, $actorId, (int) $doc->total_refund_amount);
            $this->reverseInventory($doc, $actorId, 'sales_return_adjustment_reversal', $revision->id);
            $this->reverseLedger($doc, $actorId, 'sales_return_reversal', $revision->id);

            $immutable = $doc->only(['document_number','source_type','customer_id','invoice_id','external_invoice_number','external_invoice_date','created_by','applied_by','applied_at','created_at']);
            $doc->fill([
                'default_destination_warehouse_id' => (int) ($data['default_destination_warehouse_id'] ?? $doc->default_destination_warehouse_id),
                'return_reason' => $data['return_reason'] ?? $doc->return_reason,
                'description' => $data['description'] ?? null,
                'updated_by' => $actorId,
                'status' => SalesReturnDocument::STATUS_APPLIED,
            ]);
            $doc->forceFill($immutable)->save();
            $doc->items()->delete();
            $items = $doc->isInternal() ? $this->salesReturns->prepareInternalItems($doc, $data + $immutable) : $this->salesReturns->prepareSazehItems($doc, $data + $immutable);
            foreach ($items as $i => $attrs) { $attrs['sort_order'] = $i + 1; $doc->items()->create($attrs); }
            $doc->load('items');
            foreach ($doc->items as $item) { $this->applyInventory($item, $actorId, 'sales_return_adjustment_apply', $revision->id); }
            $this->refreshTotals($doc);
            $this->creditLedger($doc, 'sales_return_adjustment', $revision->id);
            $doc->forceFill($immutable + ['status' => SalesReturnDocument::STATUS_APPLIED])->save();
            $after = $this->snapshot($doc->fresh(['items.destinationWarehouse','items.product','items.variant','customer']));
            $revision->update(['after_snapshot' => $after, 'new_total' => (int) $doc->total_refund_amount]);
            return $doc->fresh('items');
        });
    }

    public function voidApplied(SalesReturnDocument $document, int $actorId, string $reason): SalesReturnDocument
    {
        return DB::transaction(function () use ($document, $actorId, $reason) {
            $doc = SalesReturnDocument::whereKey($document->id)->lockForUpdate()->firstOrFail();
            if ($doc->isCancelled()) throw ValidationException::withMessages(['document' => 'این سند قبلاً ابطال شده است.']);
            if (! $doc->isApplied()) throw ValidationException::withMessages(['document' => 'فقط سند ثبت‌نهایی‌شده قابل حذف/ابطال است.']);
            $doc->load(['items.destinationWarehouse','items.product','items.variant','customer']);
            $before = $this->snapshot($doc);
            $revision = $this->revision($doc, 'applied_voided', $reason, $before, $actorId, (int) $doc->total_refund_amount);
            $this->reverseInventory($doc, $actorId, 'sales_return_void_reversal', $revision->id);
            $this->reverseLedger($doc, $actorId, 'sales_return_reversal', $revision->id);
            $doc->update(['status' => SalesReturnDocument::STATUS_CANCELLED, 'cancelled_by' => $actorId, 'cancelled_at' => now(), 'cancel_reason' => $reason, 'updated_by' => $actorId]);
            ActivityLog::create(['user_id'=>$actorId,'action'=>'sales_return.applied_voided','subject_type'=>SalesReturnDocument::class,'subject_id'=>$doc->id,'description'=>'ابطال برگشت از فروش '.$doc->document_number,'properties'=>['revision_id'=>$revision->id,'reason'=>$reason],'occurred_at'=>now()]);
            $revision->update(['after_snapshot' => $this->snapshot($doc->fresh(['items.destinationWarehouse','items.product','items.variant','customer'])), 'new_total' => 0]);
            return $doc->fresh('items');
        });
    }

    private function lockedApplied(int $id): SalesReturnDocument
    {
        $doc = SalesReturnDocument::whereKey($id)->lockForUpdate()->firstOrFail();
        if (! $doc->isApplied()) throw ValidationException::withMessages(['document' => $doc->isCancelled() ? 'سند ابطال‌شده قابل ویرایش نیست.' : 'فقط سند ثبت‌نهایی‌شده قابل اصلاح است.']);
        return $doc;
    }

    private function reverseInventory(SalesReturnDocument $doc, int $actorId, string $reason, int $revisionId): void
    {
        foreach ($doc->items as $item) {
            $stock = WarehouseStock::where('warehouse_id',$item->destination_warehouse_id)->where('product_variant_id',$item->product_variant_id)->lockForUpdate()->first();
            $before = (int) ($stock?->quantity ?? 0); $qty = (int) $item->return_quantity;
            if ($before < $qty) throw ValidationException::withMessages(['stock' => "امکان ویرایش سند وجود ندارد؛\nاز موجودی برگشتی «".($item->product_name_snapshot ?: $item->product?->name ?: 'کالا').' / '.($item->variant_name_snapshot ?: $item->variant?->variant_name ?: 'تنوع')."» در انبار «".($item->destinationWarehouse?->name ?: '—')."» استفاده شده است.\nموجودی فعلی: {$before}\nمقدار موردنیاز برای برگشت عملیات: {$qty}"]);
            $stock->quantity = $before - $qty; $stock->save();
            $this->movement($item, $actorId, 'out', $reason, $qty, $before, $stock->quantity, $revisionId);
        }
    }

    private function applyInventory(SalesReturnDocumentItem $item, int $actorId, string $reason, int $revisionId): void
    {
        $stock = WarehouseStock::firstOrCreate(['warehouse_id'=>$item->destination_warehouse_id,'product_variant_id'=>$item->product_variant_id], ['product_id'=>$item->product_id,'quantity'=>0]);
        $stock->lockForUpdate(); $before=(int)$stock->quantity; $stock->quantity=$before+(int)$item->return_quantity; $stock->product_id=$item->product_id; $stock->save();
        $this->movement($item, $actorId, 'in', $reason, (int)$item->return_quantity, $before, (int)$stock->quantity, $revisionId);
    }

    private function movement(SalesReturnDocumentItem $item, int $actorId, string $type, string $reason, int $qty, int $before, int $after, int $revisionId): void
    {
        StockMovement::create(['product_id'=>$item->product_id,'product_variant_id'=>$item->product_variant_id,'warehouse_id'=>$item->destination_warehouse_id,'user_id'=>$actorId,'type'=>$type,'reason'=>$reason,'quantity'=>$qty,'stock_before'=>$before,'stock_after'=>$after,'note'=>'اصلاح برگشت از فروش '.$item->document->document_number.' rev#'.$revisionId,'reference'=>$item->document->document_number.'#'.$revisionId,'reference_type'=>SalesReturnDocument::class,'reference_id'=>$item->document_id]);
    }

    private function reverseLedger(SalesReturnDocument $doc, int $actorId, string $source, int $revisionId): void
    {
        if ((int)$doc->total_refund_amount <= 0) return;
        CustomerLedger::firstOrCreate(['customer_id'=>$doc->customer_id,'reference_type'=>$source,'reference_id'=>$revisionId,'type'=>'debit'], ['amount'=>(int)$doc->total_refund_amount,'note'=>'خنثی‌سازی بستانکاری برگشت از فروش '.$doc->document_number]);
    }
    private function creditLedger(SalesReturnDocument $doc, string $source, int $revisionId): void
    {
        if ((int)$doc->total_refund_amount <= 0) return;
        CustomerLedger::firstOrCreate(['customer_id'=>$doc->customer_id,'reference_type'=>$source,'reference_id'=>$revisionId,'type'=>'credit'], ['amount'=>(int)$doc->total_refund_amount,'note'=>'بستانکاری اصلاح برگشت از فروش '.$doc->document_number]);
    }
    private function refreshTotals(SalesReturnDocument $doc): void { $doc->load('items'); $doc->update(['items_count'=>$doc->items->count(),'total_quantity'=>$doc->items->sum('return_quantity'),'total_refund_amount'=>$doc->items->sum('refund_amount')]); }
    private function revision(SalesReturnDocument $doc, string $action, string $reason, array $before, int $actorId, int $previousTotal): SalesReturnDocumentRevision { return SalesReturnDocumentRevision::create(['document_id'=>$doc->id,'action'=>$action,'token'=>(string)Str::uuid(),'reason'=>$reason,'before_snapshot'=>$before,'previous_total'=>$previousTotal,'created_by'=>$actorId,'created_at'=>now()]); }
    private function snapshot(SalesReturnDocument $doc): array { return ['document'=>$doc->only(['id','document_number','status','customer_id','source_type','invoice_id','external_invoice_number','external_invoice_date','created_at','applied_at','total_quantity','total_refund_amount']),'items'=>$doc->items->map(fn($i)=>$i->only(['id','product_id','product_variant_id','product_name_snapshot','variant_name_snapshot','destination_warehouse_id','item_condition','return_quantity','refund_unit_price','refund_amount']))->values()->all(),'ledger_refs'=>CustomerLedger::where(function($q)use($doc){$q->where('reference_type',SalesReturnDocument::class)->where('reference_id',$doc->id)->orWhere('note','like','%'.$doc->document_number.'%');})->get(['id','type','amount','reference_type','reference_id'])->toArray(),'movement_refs'=>StockMovement::where('reference',$doc->document_number)->orWhere('reference','like',$doc->document_number.'#%')->get(['id','type','reason','quantity','warehouse_id','reference','reference_type','reference_id'])->toArray()]; }
}
