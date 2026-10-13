<?php

namespace App\Services;

use App\Models\{Product, ProductVariant, Purchase, PurchaseReturn, StockMovement, Warehouse, WarehouseStock};
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Validation\ValidationException;

/** A return changes only central free stock. Purchase and financial rows are immutable here. */
class PurchaseReturnService
{
    private function fail(string $message): never { throw ValidationException::withMessages(['items'=>$message]); }

    public function warehouseId(): int
    {
        $warehouses=Warehouse::where('type','central')->get();
        if ($warehouses->count() !== 1 || !$warehouses->first()->is_active || $warehouses->first()->name !== 'انبار مرکزی') {
            $this->fail('انبار مرکزی استاندارد و فعال باید به‌صورت یکتا تعریف شده باشد.');
        }
        return (int)$warehouses->first()->id;
    }

    public function assertPurchaseMutable(Purchase $purchase): void
    {
        if (Schema::hasTable('purchase_returns') && PurchaseReturn::where('purchase_id',$purchase->id)->exists()) {
            $this->fail('این خرید سند برگشت فعال دارد. ابتدا برگشت‌های آن را بررسی و حذف کنید، سپس خرید را تغییر دهید.');
        }
    }

    public function create(array $data, int $userId): PurchaseReturn
    {
        return DB::transaction(function () use ($data,$userId) {
            // One locked counter also serializes double submissions of a new document.
            $sequence=DB::table('purchase_return_sequences')->where('id',1)->lockForUpdate()->first();
            if (!$sequence) $this->fail('شمارندهٔ اسناد برگشت تعریف نشده است.');
            $existing=PurchaseReturn::withTrashed()->where('submission_token',$data['submission_token'])->first();
            if ($existing) {
                if ((int)$existing->created_by !== $userId || $existing->trashed()) $this->fail('درخواست قبلاً استفاده شده است؛ صفحه را تازه کنید.');
                return $existing;
            }
            $purchase=Purchase::whereKey($data['purchase_id'])->lockForUpdate()->firstOrFail();
            if ((int)$purchase->supplier_id !== (int)$data['supplier_id']) $this->fail('خرید انتخاب‌شده متعلق به این تأمین‌کننده نیست.');
            $lines=$this->lines($purchase,$data['items']);
            $number=(int)$sequence->last_number+1;
            $doc=PurchaseReturn::create([
                'document_number'=>str_pad((string)$number,5,'0',STR_PAD_LEFT), 'submission_token'=>$data['submission_token'],
                'purchase_id'=>$purchase->id,'supplier_id'=>$purchase->supplier_id,'supplier_name'=>$purchase->supplier?->name ?? 'تأمین‌کننده',
                'warehouse_id'=>$this->warehouseId(),'returned_at'=>$data['returned_at'],'note'=>$data['note']??null,
                'created_by'=>$userId,'updated_by'=>$userId,'version'=>1,
            ]);
            $this->applyLines($doc,$lines,[],$userId,'ثبت برگشت از خرید');
            $this->storeLines($doc,$lines);
            $doc->revisions()->create(['action'=>'created','user_id'=>$userId,'after'=>$this->snapshot($doc)]);
            DB::table('purchase_return_sequences')->where('id',1)->update(['last_number'=>$number]);
            return $doc->fresh();
        },3);
    }

    public function update(PurchaseReturn $document, array $data, int $userId): PurchaseReturn
    {
        return DB::transaction(function () use ($document,$data,$userId) {
            $purchase=Purchase::whereKey($document->purchase_id)->lockForUpdate()->firstOrFail();
            $doc=$this->lockedDocument($document,(int)$data['version']);
            if ((int)$data['purchase_id'] !== (int)$doc->purchase_id || (int)$data['supplier_id'] !== (int)$doc->supplier_id) $this->fail('تأمین‌کننده و خریدِ سند برگشت قابل جابه‌جایی نیستند.');
            $before=$this->snapshot($doc);$old=$doc->items()->get()->keyBy('purchase_item_id')->map->toArray()->all();
            $lines=$this->lines($purchase,$data['items'],$doc,$old);
            $this->applyLines($doc,$lines,$old,$userId,'ویرایش برگشت از خرید');
            $doc->update(['returned_at'=>$data['returned_at'],'note'=>$data['note']??null,'updated_by'=>$userId,'version'=>$doc->version+1]);
            $this->storeLines($doc,$lines);
            $doc->revisions()->create(['action'=>'updated','user_id'=>$userId,'before'=>$before,'after'=>$this->snapshot($doc)]);
            return $doc->fresh();
        },3);
    }

    public function delete(PurchaseReturn $document, int $version, int $userId, ?string $reason): void
    {
        DB::transaction(function () use ($document,$version,$userId,$reason) {
            Purchase::whereKey($document->purchase_id)->lockForUpdate()->firstOrFail();
            $doc=$this->lockedDocument($document,$version);$before=$this->snapshot($doc);
            $old=$doc->items()->get()->keyBy('purchase_item_id')->map->toArray()->all();
            $this->applyLines($doc,[],$old,$userId,'حذف برگشت از خرید');
            $doc->update(['deleted_by'=>$userId,'delete_reason'=>$reason,'updated_by'=>$userId,'version'=>$doc->version+1]);
            $doc->delete();
            $doc->revisions()->create(['action'=>'deleted','user_id'=>$userId,'before'=>$before,'after'=>$this->snapshot($doc),'note'=>$reason]);
        },3);
    }

    private function lockedDocument(PurchaseReturn $document, int $version): PurchaseReturn
    {
        $doc=PurchaseReturn::withTrashed()->whereKey($document->id)->lockForUpdate()->firstOrFail();
        if ($doc->trashed() || $doc->version !== $version) $this->fail('این سند قبلاً تغییر یا حذف شده است. صفحه را تازه کنید.');
        return $doc;
    }

    private function lines(Purchase $purchase, array $input, ?PurchaseReturn $document=null, array $old=[]): array
    {
        $source=$purchase->items()->lockForUpdate()->get()->keyBy('id');$lines=[];$seen=[];
        $returned=$this->returnedQuantities($source->keys()->all(),$document?->id);
        foreach ($input as $row) {
            $id=(int)$row['purchase_item_id'];$qty=(int)$row['quantity'];
            if (isset($seen[$id])) $this->fail('یک ردیف خرید نباید دو بار انتخاب شود.');$seen[$id]=true;
            if ($qty<0 || $qty>1000000) $this->fail('تعداد برگشت معتبر نیست.');
            $item=$source->get($id);if (!$item) $this->fail('کالای انتخاب‌شده در این خرید وجود ندارد.');
            if ($qty===0) continue;
            $previous=(int)$returned->get($id,0);
            if ($qty>(int)$item->quantity-$previous) $this->fail('تعداد برگشت «'.$item->product_name.'» از باقی‌ماندهٔ خرید بیشتر است.');
            $price=(int)($old[$id]['buy_price']??$item->buy_price);
            if ($price<0 || $price>1000000000000) $this->fail('قیمت ثبت‌شدهٔ خرید معتبر نیست.');
            $lines[$id]=['purchase_item_id'=>$id,'product_id'=>(int)$item->product_id,'product_variant_id'=>$item->product_variant_id,
                'product_name'=>$item->product_name ?: 'کالا','variant_name'=>$item->variant_name,'product_code'=>$item->product_code,
                'quantity'=>$qty,'buy_price'=>$price,'line_total'=>$qty*$price];
        }
        if (!$lines) $this->fail('حداقل یک کالا با تعداد برگشت بیشتر از صفر وارد کنید.');
        return $lines;
    }

    public function returnedQuantity(int $itemId, ?int $except=null): int
    {
        return (int)$this->returnedQuantities([$itemId],$except)->get($itemId,0);
    }

    public function returnedQuantities(array $itemIds, ?int $except=null): \Illuminate\Support\Collection
    {
        return DB::table('purchase_return_items as i')->join('purchase_returns as r','r.id','=','i.purchase_return_id')
            ->whereNull('r.deleted_at')->whereIn('i.purchase_item_id',$itemIds)->when($except,fn($q)=>$q->where('r.id','!=',$except))
            ->groupBy('i.purchase_item_id')->selectRaw('i.purchase_item_id, SUM(i.quantity) as returned')->pluck('returned','purchase_item_id');
    }

    private function applyLines(PurchaseReturn $doc, array $new, array $old, int $userId, string $action): void
    {
        $deltas=[];
        foreach ([[$old,1],[$new,-1]] as [$rows,$sign]) foreach ($rows as $row) {
            $key=$row['product_id'].':'.($row['product_variant_id']??0);
            $deltas[$key]??=['product_id'=>(int)$row['product_id'],'variant_id'=>$row['product_variant_id']? (int)$row['product_variant_id']:null,'delta'=>0];
            $deltas[$key]['delta']+=$sign*(int)$row['quantity'];
        }
        // Same variant-first locking order as the existing reservation flow.
        uasort($deltas,fn($a,$b)=>($a['variant_id']??0)<=>($b['variant_id']??0) ?: $a['product_id']<=>$b['product_id']);
        foreach ($deltas as $row) {
            if (!$row['delta']) continue;
            if ($row['variant_id']) {
                if (!ProductVariant::whereKey($row['variant_id'])->where('product_id',$row['product_id'])->lockForUpdate()->first()) $this->fail('تنوع مرجع خرید دیگر معتبر نیست.');
            } else {
                if (!Product::whereKey($row['product_id'])->lockForUpdate()->first() || ProductVariant::where('product_id',$row['product_id'])->exists()) $this->fail('این ردیف خرید تنوع مشخص ندارد؛ موجودیِ تجمیعی قابل برگشت نیست.');
            }
            $stocks=WarehouseStock::where('warehouse_id',$doc->warehouse_id)->where('product_id',$row['product_id'])->where('product_variant_id',$row['variant_id'])->lockForUpdate()->get();
            if ($stocks->count()>1) $this->fail('موجودی این کالا رکورد تکراری دارد؛ ابتدا بررسی شود.');
            $before=(int)($stocks->first()?->quantity??0);
            if ($before+$row['delta']<0) $this->fail('موجودی آزاد انبار مرکزی کافی نیست؛ رزرو کالا قابل برگشت نیست.');
            WarehouseStockService::change((int)$doc->warehouse_id,$row['product_id'],$row['delta'],$row['variant_id']);
            StockMovement::create(['product_id'=>$row['product_id'],'product_variant_id'=>$row['variant_id'],'warehouse_id'=>$doc->warehouse_id,
                'user_id'=>$userId,'type'=>$row['delta']>0?'in':'out','reason'=>$row['delta']>0?'purchase_return_restore':'purchase_return',
                'transaction_type'=>'purchase_return','quantity'=>abs($row['delta']),'stock_before'=>$before,'stock_after'=>$before+$row['delta'],
                'reference'=>'PR-'.$doc->document_number,'reference_type'=>PurchaseReturn::class,'reference_id'=>$doc->id,'note'=>$action.' | خرید #'.$doc->purchase_id]);
        }
    }

    private function storeLines(PurchaseReturn $doc, array $lines): void
    {
        $doc->items()->delete();$doc->items()->createMany(array_values($lines));
        $doc->update(['total_amount'=>array_sum(array_column($lines,'line_total')),'total_quantity'=>array_sum(array_column($lines,'quantity'))]);
    }
    private function snapshot(PurchaseReturn $doc): array
    {
        return $doc->fresh()->toArray()+['items'=>$doc->items()->get()->toArray()];
    }
}
