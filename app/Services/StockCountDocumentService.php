<?php

namespace App\Services;

use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockCountDocument;
use App\Models\StockCountDocumentHistory;
use App\Models\StockCountDocumentItem;
use App\Models\StockMovement;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class StockCountDocumentService
{
    public function centralWarehouse(): Warehouse
    {
        return Warehouse::firstOrCreate(['type' => 'central', 'name' => 'انبار مرکزی'], ['is_active' => true]);
    }

    public function activeReserved(ProductVariant $variant): int
    {
        return max(0, (int) $variant->reserved);
    }

    public function createProductDraft(array $payload, int $userId): StockCountDocument
    {
        return DB::transaction(function () use ($payload, $userId) {
            $warehouse = $this->centralWarehouse();
            $product = Product::query()->findOrFail((int) $payload['product_id']);
            $document = StockCountDocument::create([
                'document_number' => $this->nextDocumentNumber(), 'type' => 'product', 'warehouse_id' => $warehouse->id,
                'product_id' => $product->id, 'document_date' => $payload['document_date'], 'status' => 'draft',
                'description' => $payload['description'] ?? null, 'created_by' => $userId, 'updated_by' => $userId,
            ]);
            $actuals = $payload['actual_quantities'] ?? [];
            $notes = $payload['notes'] ?? [];
            $rows = [];
            ProductVariant::query()->where('product_id', $product->id)->orderBy('id')->chunkById(500, function ($variants) use (&$rows, $document, $warehouse, $product, $actuals, $notes) {
                foreach ($variants as $variant) {
                    $stock = $this->lockOrCreateStock((int) $warehouse->id, (int) $product->id, (int) $variant->id, false);
                    $available = (int) $stock->quantity;
                    $reserved = $this->activeReserved($variant);
                    $actual = array_key_exists($variant->id, $actuals) && $actuals[$variant->id] !== '' ? (int) $actuals[$variant->id] : null;
                    $rows[] = $this->itemPayload($document, $warehouse, $product, $variant, $stock, $available, $reserved, $actual, $notes[$variant->id] ?? null);
                }
                if (count($rows) >= 500) { StockCountDocumentItem::insert($rows); $rows = []; }
            });
            if ($rows) StockCountDocumentItem::insert($rows);
            $this->refreshSummary($document);
            $this->addHistory($document->id, 'created', null, ['status' => 'draft', 'type' => 'product'], 'ایجاد پیش‌نویس انبارگردانی محصولی', $userId);
            return $document->fresh(['warehouse','product','items.variant']);
        });
    }

    public function updateProductDraft(StockCountDocument $document, array $payload, int $userId): StockCountDocument
    {
        if ($document->status !== 'draft') abort(422, 'فقط سند پیش‌نویس قابل ویرایش است.');
        return DB::transaction(function () use ($document, $payload, $userId) {
            $document = StockCountDocument::query()->lockForUpdate()->findOrFail($document->id);
            $actuals = $payload['actual_quantities'] ?? [];
            $notes = $payload['notes'] ?? [];
            $document->update(['document_date' => $payload['document_date'], 'description' => $payload['description'] ?? null, 'updated_by' => $userId]);
            foreach ($document->items()->lockForUpdate()->get() as $item) {
                $vid = (int) $item->product_variant_id;
                $actual = array_key_exists($vid, $actuals) && $actuals[$vid] !== '' ? (int) $actuals[$vid] : null;
                $item->update(['actual_quantity' => $actual, 'description' => $notes[$vid] ?? null]);
            }
            $this->refreshSummary($document);
            $this->addHistory($document->id, 'updated', null, ['status' => 'draft'], 'ذخیره پیش‌نویس انبارگردانی محصولی', $userId);
            return $document->fresh(['warehouse','product','items.variant']);
        });
    }

    public function finalize(StockCountDocument $document, int $userId, bool $confirmEmptyAsZero = false): StockCountDocument
    {
        if (! $confirmEmptyAsZero) throw ValidationException::withMessages(['confirm_empty_as_zero' => 'تأیید صفرشدن مقدارهای خالی الزامی است.']);
        return DB::transaction(function () use ($document, $userId) {
            $document = StockCountDocument::query()->lockForUpdate()->findOrFail($document->id);
            if ($document->status !== 'draft') abort(422, 'فقط سند پیش‌نویس قابل نهایی‌سازی است.');
            if (($document->type ?? 'product') !== 'product') abort(422, 'در این نسخه فقط انبارگردانی محصولی قابل اعمال است.');
            $warehouse = $this->centralWarehouse();
            if ((int) $document->warehouse_id !== (int) $warehouse->id) abort(422, 'انبار سند با انبار مرکزی پروژه همخوان نیست.');
            $items = $document->items()->lockForUpdate()->orderBy('product_variant_id')->get();
            $variantIds = $items->pluck('product_variant_id')->map(fn($v)=>(int)$v)->all();
            $currentIds = ProductVariant::query()->where('product_id', (int) $document->product_id)->orderBy('id')->pluck('id')->map(fn($v)=>(int)$v)->all();
            if ($currentIds !== $variantIds) abort(422, 'مجموعه تنوع‌های محصول پس از شروع انبارگردانی تغییر کرده است. سند را به‌روزرسانی کنید.');
            $variants = ProductVariant::query()->whereIn('id', $variantIds)->lockForUpdate()->get()->keyBy('id');
            $stocks = WarehouseStock::query()->where('warehouse_id', $warehouse->id)->whereIn('product_variant_id', $variantIds)->lockForUpdate()->get()->keyBy('product_variant_id');
            foreach ($items as $item) if (! $stocks->has((int)$item->product_variant_id)) $stocks[(int)$item->product_variant_id] = $this->lockOrCreateStock($warehouse->id, (int)$document->product_id, (int)$item->product_variant_id, true);
            $errors = [];
            foreach ($items as $item) {
                $variant = $variants[(int)$item->product_variant_id]; $stock = $stocks[(int)$item->product_variant_id];
                $reserved = $this->activeReserved($variant); $available = (int)$stock->quantity; $actual = $item->actual_quantity === null ? 0 : (int)$item->actual_quantity;
                if ($reserved !== (int)$item->reserved_at_start || $available !== (int)$item->system_available_at_start) $errors[] = ($item->variant_name_snapshot ?: ('#'.$item->product_variant_id));
                if ($actual < $reserved) $errors[] = ($item->variant_name_snapshot ?: ('#'.$item->product_variant_id)) . ' (موجودی واقعی کمتر از رزرو)';
            }
            if ($errors) abort(422, 'موجودی یا رزرو '.count($errors).' تنوع پس از شروع شمارش تغییر کرده است: '.implode('، ', array_slice($errors,0,8)));
            foreach ($items as $item) {
                $stock = $stocks[(int)$item->product_variant_id]; $reserved = (int)$item->reserved_at_start; $actual = $item->actual_quantity === null ? 0 : (int)$item->actual_quantity;
                $newAvailable = $actual - $reserved; $diff = $actual - (int)$item->expected_physical_at_start; $before = (int)$stock->quantity;
                $stock->update(['quantity' => $newAvailable]);
                WarehouseStockService::syncVariantStockFromCentral((int)$item->product_variant_id);
                $item->update(['actual_quantity'=>$actual, 'new_available'=>$newAvailable, 'difference_quantity'=>$diff, 'system_quantity'=>(int)$item->system_available_at_start]);
                if ($diff !== 0) StockMovement::create(['product_id'=>(int)$item->product_id,'product_variant_id'=>(int)$item->product_variant_id,'warehouse_id'=>(int)$warehouse->id,'user_id'=>$userId,'type'=>$diff>0?'in':'out','reason'=>'adjustment','transaction_type'=>'stocktake_adjustment','quantity'=>abs($newAvailable-$before),'stock_before'=>$before,'stock_after'=>$newAvailable,'note'=>'تعدیل ناشی از انبارگردانی سند '.$document->document_number,'reference'=>$document->document_number,'reference_type'=>'stock_count_document','reference_id'=>$document->id]);
            }
            WarehouseStockService::syncProductStockFromCentral((int)$document->product_id);
            $this->refreshSummary($document);
            $document->update(['status'=>'finalized','finalized_by'=>$userId,'finalized_at'=>now(),'updated_by'=>$userId]);
            $this->addHistory($document->id, 'finalized', null, ['status'=>'finalized'], 'اعمال انبارگردانی محصولی در انبار مرکزی', $userId);
            DB::afterCommit(fn() => logger()->info('سند انبارگردانی اعمال شد', ['document_id'=>$document->id,'document_number'=>$document->document_number]));
            return $document->fresh(['warehouse','product','items.variant','creator','finalizer']);
        });
    }

    public function cancel(StockCountDocument $document, int $userId, ?string $reason = null): StockCountDocument
    {
        if ($document->status === 'finalized') abort(422, 'لغو سند اعمال‌شده مجاز نیست.');
        if ($document->status === 'cancelled') return $document;
        $document->update(['status'=>'cancelled','cancelled_by'=>$userId,'cancelled_at'=>now(),'cancel_reason'=>$reason,'updated_by'=>$userId]);
        $this->addHistory($document->id, 'cancelled', null, ['status'=>'cancelled'], 'لغو پیش‌نویس انبارگردانی', $userId);
        return $document->fresh(['warehouse','product','items.variant']);
    }

    public function variantsPage(int $productId, ?StockCountDocument $document = null, int $page = 1, int $limit = 100, ?string $q = null): array
    {
        $query = $document ? $document->items()->with('variant')->orderBy('product_variant_id') : ProductVariant::query()->where('product_id',$productId)->orderBy('id');
        if ($q) $query->where(function($x) use($q,$document){ $cols=$document?['variant_name_snapshot','sku_snapshot']:['variant_name','variant_code','variety_name','variety_code']; foreach($cols as $c) $x->orWhere($c,'like','%'.$q.'%'); });
        $p = $query->paginate($limit, ['*'], 'page', $page);
        $warehouse = $this->centralWarehouse();
        $rows = $p->getCollection()->map(function($row) use($document,$warehouse,$productId){
            if ($document) return $this->itemResource($row);
            $stock = WarehouseStock::query()->where('warehouse_id',$warehouse->id)->where('product_variant_id',$row->id)->first(); $reserved=$this->activeReserved($row); $available=(int)($stock?->quantity ?? 0);
            return ['variant_id'=>(int)$row->id,'name'=>$row->variant_name ?: $row->variety_name ?: ('#'.$row->id),'sku'=>$row->variant_code ?: $row->variety_code,'barcode'=>$row->variant_code,'sales_enabled'=>(bool)$row->sales_enabled && (bool)$row->is_active,'system_available'=>$available,'active_reserved'=>$reserved,'expected_physical'=>$available+$reserved,'stock_updated_at'=>$stock?->updated_at?->toDateTimeString(),'actual_physical'=>null,'new_available'=>null,'difference'=>null,'note'=>null];
        })->values();
        return ['data'=>$rows,'meta'=>['current_page'=>$p->currentPage(),'last_page'=>$p->lastPage(),'has_more'=>$p->hasMorePages(),'total'=>$p->total()]];
    }

    private function itemPayload($document,$warehouse,$product,$variant,$stock,int $available,int $reserved,?int $actual,?string $note): array
    { $expected=$available+$reserved; return ['document_id'=>$document->id,'warehouse_id'=>$warehouse->id,'product_id'=>$product->id,'product_variant_id'=>$variant->id,'warehouse_stock_id'=>$stock->id,'product_name_snapshot'=>$product->name,'variant_name_snapshot'=>$variant->variant_name ?: $variant->variety_name ?: ('#'.$variant->id),'sku_snapshot'=>$variant->variant_code ?: $variant->variety_code,'system_quantity'=>$available,'system_available_at_start'=>$available,'reserved_at_start'=>$reserved,'expected_physical_at_start'=>$expected,'actual_quantity'=>$actual,'new_available'=>null,'difference_quantity'=>$actual===null?0:$actual-$expected,'warehouse_stock_updated_at_start'=>$stock->updated_at,'stock_updated_at_start'=>$variant->updated_at,'description'=>$note,'created_at'=>now(),'updated_at'=>now()]; }
    private function itemResource($item): array { $actual=$item->actual_quantity; return ['variant_id'=>(int)$item->product_variant_id,'name'=>$item->variant_name_snapshot,'sku'=>$item->sku_snapshot,'barcode'=>$item->sku_snapshot,'sales_enabled'=>(bool)($item->variant?->sales_enabled && $item->variant?->is_active),'system_available'=>(int)$item->system_available_at_start,'active_reserved'=>(int)$item->reserved_at_start,'expected_physical'=>(int)$item->expected_physical_at_start,'stock_updated_at'=>$item->warehouse_stock_updated_at_start?->toDateTimeString(),'actual_physical'=>$actual,'new_available'=>$actual===null?null:$actual-(int)$item->reserved_at_start,'difference'=>$actual===null?null:$actual-(int)$item->expected_physical_at_start,'note'=>$item->description]; }
    private function lockOrCreateStock(int $warehouseId,int $productId,int $variantId,bool $lock): WarehouseStock { $q=WarehouseStock::query()->where('warehouse_id',$warehouseId)->where('product_id',$productId)->where('product_variant_id',$variantId); if($lock)$q->lockForUpdate(); $s=$q->first(); return $s ?: WarehouseStock::create(['warehouse_id'=>$warehouseId,'product_id'=>$productId,'product_variant_id'=>$variantId,'quantity'=>0]); }
    private function refreshSummary(StockCountDocument $document): void { $items=$document->items()->get(); $actualTotal=0;$inc=0;$dec=0;$counted=0;$zero=0;$incC=0;$decC=0; foreach($items as $i){$a=$i->actual_quantity; if($a!==null)$counted++; $final=$a??0; if($a===null)$zero++; $actualTotal+=$final; $d=$final-(int)$i->expected_physical_at_start; if($d>0){$inc+=$d;$incC++;} if($d<0){$dec+=abs($d);$decC++;}} $document->update(['variants_count'=>$items->count(),'counted_count'=>$counted,'zeroed_count'=>$zero,'increased_count'=>$incC,'decreased_count'=>$decC,'total_before'=>(int)$items->sum('expected_physical_at_start'),'total_actual'=>$actualTotal,'total_increase'=>$inc,'total_decrease'=>$dec]); }
    private function addHistory(int $documentId,string $actionType,mixed $oldValue,mixed $newValue,?string $description,int $userId): void { StockCountDocumentHistory::create(['document_id'=>$documentId,'action_type'=>$actionType,'old_value'=>$oldValue,'new_value'=>$newValue,'description'=>$description,'done_by'=>$userId,'done_at'=>now()]); }
    private function nextDocumentNumber(): string { $prefix='STC-'.now()->format('Ymd').'-'; $last=StockCountDocument::query()->where('document_number','like',$prefix.'%')->orderByDesc('id')->value('document_number'); return $prefix.str_pad((string)(($last?(int)substr($last,-4):0)+1),4,'0',STR_PAD_LEFT); }
}
