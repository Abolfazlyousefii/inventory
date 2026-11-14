<?php

namespace App\Services;

use App\Models\{Product, ProductVariant, Purchase, PurchaseReturn, StockMovement, Warehouse, WarehouseStock};
use Illuminate\Support\Facades\{DB, Schema};
use Illuminate\Validation\ValidationException;

/** A return changes free stock in its recorded source warehouse and synchronizes its supplier debit atomically. */
class PurchaseReturnService
{
    private function fail(string $message): never { throw ValidationException::withMessages(['items'=>$message]); }

    public function warehouseId(?int $selected=null): int
    {
        if ($selected !== null) {
            $warehouse=Warehouse::whereKey($selected)->first();
            if (!$warehouse || !$warehouse->is_active) {
                throw ValidationException::withMessages(['warehouse_id'=>'انبار مبدأ باید معتبر و فعال باشد.']);
            }
            return (int)$warehouse->id;
        }
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
                if (isset($data['warehouse_id']) && (int)$data['warehouse_id'] !== (int)$existing->warehouse_id) $this->fail('این درخواست قبلاً برای انبار دیگری ثبت شده است؛ صفحه را تازه کنید.');
                return $existing;
            }
            $purchase=Purchase::whereKey($data['purchase_id'])->lockForUpdate()->firstOrFail();
            if ((int)$purchase->supplier_id !== (int)$data['supplier_id']) $this->fail('خرید انتخاب‌شده متعلق به این تأمین‌کننده نیست.');
            if ($purchase->supplier?->isInventoryAdjustmentSource()) {
                $this->fail('خریدهای تاریخی کسری و اضافی، مرجع برگشت از خرید به تأمین‌کننده نیستند. یک خرید واقعی از همان تأمین‌کننده انتخاب کنید.');
            }
            $lines=$this->lines($purchase,$data['items']);
            $number=(int)$sequence->last_number+1;
            $doc=PurchaseReturn::create([
                'document_number'=>str_pad((string)$number,5,'0',STR_PAD_LEFT), 'submission_token'=>$data['submission_token'],
                'purchase_id'=>$purchase->id,'supplier_id'=>$purchase->supplier_id,'supplier_name'=>$purchase->supplier?->name ?? 'تأمین‌کننده',
                'warehouse_id'=>$this->warehouseId(isset($data['warehouse_id']) ? (int)$data['warehouse_id'] : null),'returned_at'=>$data['returned_at'],'note'=>$data['note']??null,
                'created_by'=>$userId,'updated_by'=>$userId,'version'=>1,
            ]);
            $this->applyLines($doc,$lines,[],$userId,'ثبت برگشت از خرید');
            $this->storeLines($doc,$lines);
            app(SupplierLedgerService::class)->syncPurchaseReturnDebit($doc->fresh());
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
            if (isset($data['warehouse_id']) && (int)$data['warehouse_id'] !== (int)$doc->warehouse_id) $this->fail('انبار مبدأ سند ثبت‌شده قابل تغییر نیست؛ برای انتخاب انبار دیگر، سند را حذف و دوباره ثبت کنید.');
            $before=$this->snapshot($doc);$old=$doc->items()->get()->keyBy('purchase_item_id')->map->toArray()->all();
            $lines=$this->lines($purchase,$data['items'],$doc,$old);
            $this->applyLines($doc,$lines,$old,$userId,'ویرایش برگشت از خرید');
            $doc->update(['returned_at'=>$data['returned_at'],'note'=>$data['note']??null,'updated_by'=>$userId,'version'=>$doc->version+1]);
            $this->storeLines($doc,$lines);
            $revision = $doc->revisions()->create(['action'=>'updated','user_id'=>$userId,'before'=>$before,'after'=>$this->snapshot($doc)]);
            app(SupplierLedgerService::class)->syncPurchaseReturnDebit($doc->fresh(), $revision);
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
            $revision = $doc->revisions()->create(['action'=>'deleted','user_id'=>$userId,'before'=>$before,'after'=>$this->snapshot($doc),'note'=>$reason]);
            app(SupplierLedgerService::class)->syncPurchaseReturnDebit($doc, $revision);
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
        $returnedAmounts = $this->returnedAmounts($source->keys()->all(), $document?->id);
        $netLineTotals=$this->purchaseLineNetTotals($purchase,$source);
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
            $totalQuantity=max(0,(int)$item->quantity);
            $netLineTotal=(int)($netLineTotals[$id]??0);
            $amountBefore=(int) ($returnedAmounts[$id] ?? 0);
            if ($amountBefore > $netLineTotal) $this->fail('مبالغ برگشت قبلی از مبلغ خالص خرید بیشتر است؛ سابقه مالی این ردیف باید بررسی شود.');
            $amountAfter=$this->proportionalQuantityAmount($netLineTotal,$previous+$qty,$totalQuantity);
            $lines[$id]=['purchase_item_id'=>$id,'product_id'=>(int)$item->product_id,'product_variant_id'=>$item->product_variant_id,
                'product_name'=>$item->product_name ?: 'کالا','variant_name'=>$item->variant_name,'product_code'=>$item->product_code,
                'quantity'=>$qty,'buy_price'=>$price,'line_total'=>max(0,$amountAfter-$amountBefore)];
        }
        if (!$lines) $this->fail('حداقل یک کالا با تعداد برگشت بیشتر از صفر وارد کنید.');
        return $lines;
    }

    /**
     * Resolve each original purchase row to its net amount after item and
     * invoice discounts. Invoice discount is distributed proportionally using
     * integer arithmetic so the allocated shares add up exactly.
     */
    public function purchaseLineNetTotals(Purchase $purchase, \Illuminate\Support\Collection $items): array
    {
        $hasLineSubtotal = Schema::hasColumn('purchase_items', 'line_subtotal');
        $hasItemDiscount = Schema::hasColumn('purchase_items', 'discount_amount');
        $hasLineTotal = Schema::hasColumn('purchase_items', 'line_total');
        $lineTotals = [];
        $itemDiscountTotal = 0;

        foreach ($items as $item) {
            $fallbackGross = max(0, (int) $item->quantity) * max(0, (int) $item->buy_price);
            $gross = $hasLineSubtotal ? (int) ($item->line_subtotal ?? $fallbackGross) : $fallbackGross;
            if ($gross <= 0 && $fallbackGross > 0) {
                $gross = $fallbackGross;
            }

            $itemDiscount = $hasItemDiscount ? max(0, (int) ($item->discount_amount ?? 0)) : 0;
            $storedLineTotal = $hasLineTotal ? $item->line_total : null;
            if ($storedLineTotal !== null && ((int) $storedLineTotal > 0 || $gross === 0 || $itemDiscount >= $gross)) {
                $lineTotal = (int) $storedLineTotal;
            } else {
                $lineTotal = max(0, $gross - $itemDiscount);
            }

            if ($gross > 0) {
                $lineTotal = min($lineTotal, $gross);
            }

            $lineTotals[(int) $item->id] = max(0, $lineTotal);
            $itemDiscountTotal += $itemDiscount;
        }

        $baseTotal = array_sum($lineTotals);
        $invoiceDiscount = min(
            $baseTotal,
            max(0, (int) ($purchase->total_discount ?? 0) - $itemDiscountTotal)
        );

        if ($baseTotal === 0 || $invoiceDiscount === 0) {
            return $lineTotals;
        }

        $shares = [];
        $remainders = [];
        $allocated = 0;

        foreach ($lineTotals as $id => $lineTotal) {
            [$share, $remainder] = $this->multiplyDivide($invoiceDiscount, $lineTotal, $baseTotal);
            $shares[$id] = min($lineTotal, $share);
            $remainders[$id] = $remainder;
            $allocated += $shares[$id];
        }

        $remaining = max(0, $invoiceDiscount - $allocated);
        arsort($remainders, SORT_NUMERIC);

        foreach (array_keys($remainders) as $id) {
            if ($remaining <= 0) {
                break;
            }
            if ($shares[$id] < $lineTotals[$id]) {
                $shares[$id]++;
                $remaining--;
            }
        }

        foreach ($lineTotals as $id => $lineTotal) {
            $lineTotals[$id] = max(0, $lineTotal - $shares[$id]);
        }

        return $lineTotals;
    }

    /**
     * Returns floor(left * right / divisor) and its exact remainder without
     * overflowing a 64-bit integer.
     *
     * @return array{0:int,1:int}
     */
    private function multiplyDivide(int $left, int $right, int $divisor): array
    {
        if ($divisor <= 0 || $left <= 0 || $right <= 0) {
            return [0, 0];
        }

        if ($left > $divisor) {
            throw new \InvalidArgumentException('The proportional numerator must not exceed its divisor.');
        }

        $quotient = 0;
        $remainder = 0;

        for ($bit = 62; $bit >= 0; $bit--) {
            $quotient *= 2;

            if ($remainder >= $divisor - $remainder) {
                $remainder -= ($divisor - $remainder);
                $quotient++;
            } else {
                $remainder += $remainder;
            }

            if (($right & (1 << $bit)) !== 0) {
                if ($remainder >= $divisor - $left) {
                    $remainder -= ($divisor - $left);
                    $quotient++;
                } else {
                    $remainder += $left;
                }
            }
        }

        return [$quotient, $remainder];
    }

    private function proportionalQuantityAmount(int $total, int $quantity, int $totalQuantity): int
    {
        if ($total <= 0 || $quantity <= 0 || $totalQuantity <= 0) {
            return 0;
        }

        $quantity = min($quantity, $totalQuantity);
        $whole = intdiv($total, $totalQuantity);
        $remainder = $total % $totalQuantity;

        return ($whole * $quantity) + $this->multiplyDivide($remainder, $quantity, $totalQuantity)[0];
    }

    public function returnedQuantity(int $itemId, ?int $except=null): int
    {
        return (int)$this->returnedQuantities([$itemId],$except)->get($itemId,0);
    }

    public function returnedAmounts(array $itemIds, ?int $except = null): \Illuminate\Support\Collection
    {
        return DB::table('purchase_return_items as i')->join('purchase_returns as r','r.id','=','i.purchase_return_id')
            ->whereNull('r.deleted_at')->whereIn('i.purchase_item_id', $itemIds)
            ->when($except, fn ($q) => $q->where('r.id','!=',$except))
            ->groupBy('i.purchase_item_id')->selectRaw('i.purchase_item_id, SUM(i.line_total) as amount')->pluck('amount','purchase_item_id');
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
            if ($before+$row['delta']<0) $this->fail('موجودی آزاد انبار مبدأ کافی نیست؛ موجودی سایر انبارها و کالای رزروشده قابل استفاده نیست.');
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
