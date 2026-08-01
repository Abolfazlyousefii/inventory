<?php

namespace App\Services;

use App\Models\{Category,Customer,CustomerLedger,Invoice,InvoiceItem,ModelList,Product,ProductVariant,SalesReturnDocument,SalesReturnDocumentItem,StockMovement,Warehouse,WarehouseStock};
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class SalesReturnService
{
    public function __construct(private SalesReturnCalculationService $calculator, private SalesReturnNewProductPayloadNormalizer $normalizer) {}

    public function createDraft(array $data, ?int $actorId): SalesReturnDocument
    {
        return DB::transaction(function () use ($data, $actorId) {
            $document = new SalesReturnDocument([
                'document_number' => $this->nextDocumentNumber(),
                'status' => SalesReturnDocument::STATUS_DRAFT,
                'created_by' => $actorId,
            ]);

            return $this->persistDraft($document, $data, $actorId);
        });
    }
    public function updateDraft(SalesReturnDocument $doc, array $data, ?int $actorId): SalesReturnDocument { if(!$doc->isDraft()) throw ValidationException::withMessages(['document'=>'فقط پیش‌نویس قابل ویرایش است.']); return DB::transaction(fn()=> $this->persistDraft($doc, $data, $actorId)); }
    private function persistDraft(SalesReturnDocument $doc, array $data, ?int $actorId): SalesReturnDocument
    {
        $source=$data['source_type']; if(blank($doc->document_number)) $doc->document_number=$this->nextDocumentNumber(); $doc->fill(['source_type'=>$source,'customer_id'=>(int)$data['customer_id'],'invoice_id'=>$source===SalesReturnDocument::SOURCE_INTERNAL_INVOICE?(int)($data['invoice_id']??0):null,'external_invoice_number'=>$source===SalesReturnDocument::SOURCE_SAZEH_HESAB?($data['external_invoice_number']??null):null,'external_invoice_date'=>$source===SalesReturnDocument::SOURCE_SAZEH_HESAB?($data['external_invoice_date']??null):null,'default_destination_warehouse_id'=>(int)($data['default_destination_warehouse_id']??0),'return_reason'=>$data['return_reason']??null,'reference_number'=>array_key_exists('reference_number',$data)?$data['reference_number']:$doc->reference_number,'description'=>$data['description']??null,'updated_by'=>$actorId]); $doc->save();
        $doc->items()->delete(); $items=$source===SalesReturnDocument::SOURCE_INTERNAL_INVOICE?$this->prepareInternalItems($doc,$data):$this->prepareSazehItems($doc,$data); foreach($items as $i=>$attrs){$attrs['sort_order']=$i+1; $doc->items()->create($attrs);} $this->refreshTotals($doc); return $doc->fresh('items');
    }
    public function cancelDraft(SalesReturnDocument $doc, ?int $actorId, ?string $reason=null): SalesReturnDocument { if(!$doc->isDraft()) throw ValidationException::withMessages(['document'=>'فقط پیش‌نویس قابل لغو است.']); $doc->update(['status'=>SalesReturnDocument::STATUS_CANCELLED,'cancelled_by'=>$actorId,'cancelled_at'=>now(),'cancel_reason'=>$reason]); return $doc; }
    public function apply(SalesReturnDocument $document, ?int $actorId): SalesReturnDocument
    { return DB::transaction(function() use($document,$actorId){ $doc=SalesReturnDocument::query()->whereKey($document->id)->lockForUpdate()->firstOrFail(); if($doc->isApplied()) return $doc; if(!$doc->isDraft()) throw ValidationException::withMessages(['document'=>'فقط پیش‌نویس قابل ثبت نهایی است.']); Customer::query()->whereKey($doc->customer_id)->lockForUpdate()->firstOrFail(); if($doc->isInternal()) Invoice::query()->whereKey($doc->invoice_id)->lockForUpdate()->firstOrFail();
            $doc->load('items');
            if($doc->isInternal()) $this->assertInternalReturnablesStillValid($doc);
            if($doc->isSazehHesab()) $this->materializeNewProductGroups($doc);
            foreach($doc->items as $item){
                $item->refresh(); if(!$item->product_variant_id) throw ValidationException::withMessages(['items.'.$item->sort_order.'.product_variant_id'=>'تنوع کالا نامعتبر است.']); $this->recordInventoryEntry($item,$actorId); }
            $this->refreshTotals($doc); $this->recordCustomerCredit($doc,$actorId); $doc->update(['status'=>SalesReturnDocument::STATUS_APPLIED,'applied_by'=>$actorId,'applied_at'=>now()]); return $doc->fresh('items'); }); }

    public function prepareInternalItems(SalesReturnDocument $doc, array $data): array
    { $invoice=Invoice::with('items.product','items.variant')->findOrFail((int)$data['invoice_id']); if((int)$invoice->customer_id !== (int)$data['customer_id']) throw ValidationException::withMessages(['invoice_id'=>'فاکتور متعلق به مشتری انتخاب‌شده نیست.']); if($invoice->status !== Invoice::STATUS_SHIPPED && empty($data['override_invoice_status'])) throw ValidationException::withMessages(['invoice_id'=>'فقط فاکتور ارسال‌شده مجاز است.']); $inputRows=collect($data['items']??[])->keyBy(fn($r)=>(int)($r['invoice_item_id']??0)); $rows=$this->calculator->calculateInternalPreview($invoice,$data['items']??[]); $out=[]; foreach($rows as $r){ $it=$r['invoice_item']; $input=$inputRows->get((int)$it->id, []); $wh=$this->rowDestinationWarehouse($data, $input); $cond=$this->rowCondition($input, $wh); $out[]=['invoice_item_id'=>$it->id,'product_id'=>$it->product_id,'product_variant_id'=>$it->variant_id,'product_name_snapshot'=>$it->product?->name,'variant_name_snapshot'=>$it->variant?->variant_name,'sku_snapshot'=>$it->variant?->variant_code,'barcode_snapshot'=>$it->variant?->variant_code,'item_source'=>SalesReturnDocumentItem::SOURCE_INVOICE_ITEM,'item_condition'=>$cond,'destination_warehouse_id'=>$wh->id,'sold_quantity_snapshot'=>(int)$it->quantity,'previously_returned_quantity_snapshot'=>$r['previous_quantity'],'return_quantity'=>$r['quantity'],'unit_price_snapshot'=>(int)$it->price,'line_discount_snapshot'=>(int)($it->line_discount_amount??0),'allocated_invoice_discount_snapshot'=>$r['allocated_discount'],'refund_unit_price'=>$r['refund_unit_price'],'refund_amount'=>$r['refund_amount']]; } return $out; }
    public function prepareSazehItems(SalesReturnDocument $doc, array $data): array
    { $out=[]; foreach(($data['items']??[]) as $idx=>$row){ $qty=(int)($row['return_quantity']??0); if($qty<=0) continue; $wh=$this->rowDestinationWarehouse($data, $row); $cond=$this->rowCondition($row, $wh); $source=$row['item_source']; $unit=(int)($row['refund_unit_price']??0); $attrs=['item_source'=>$source,'item_condition'=>$cond,'destination_warehouse_id'=>$wh->id,'return_quantity'=>$qty,'refund_unit_price'=>$unit,'refund_amount'=>$qty*$unit]; if($source===SalesReturnDocumentItem::SOURCE_EXISTING_PRODUCT){ $variant=ProductVariant::with('product')->findOrFail((int)$row['product_variant_id']); $attrs += ['product_id'=>$variant->product_id,'product_variant_id'=>$variant->id,'product_name_snapshot'=>$variant->product?->name,'variant_name_snapshot'=>$variant->variant_name,'sku_snapshot'=>$variant->variant_code,'barcode_snapshot'=>$variant->variant_code]; } else { $payload=$this->normalizer->normalize($row['new_product_payload']??[]); $attrs += ['product_name_snapshot'=>$payload['name']??$payload['product_name']??null,'variant_name_snapshot'=>$payload['variant_name']??($payload['selected_variants'][0]['display_name']??null),'sku_snapshot'=>$payload['selected_variants'][0]['preview_code']??($payload['sku']??null),'barcode_snapshot'=>$payload['barcode']??null,'purchase_price'=>(int)($payload['purchase_price']??$row['purchase_price']??0),'sell_price'=>(int)($payload['sell_price']??$row['sell_price']??0),'new_product_payload'=>$payload]; } $out[]=$attrs; } return $out; }
    private function documentDestinationWarehouse(array $data): Warehouse { return $this->resolveWarehouseId((int)($data['default_destination_warehouse_id']??0), 'default_destination_warehouse_id'); }
    private function rowDestinationWarehouse(array $data, array $row): Warehouse { return $this->resolveWarehouseId((int)($row['destination_warehouse_id'] ?? $data['default_destination_warehouse_id'] ?? 0), 'items.destination_warehouse_id'); }
    private function resolveWarehouseId(int $id, string $field): Warehouse { $warehouse=Warehouse::whereKey($id)->where('is_active',true)->whereIn('type',['central','return'])->first(); if(!$warehouse) throw ValidationException::withMessages([$field=>'انبار مقصد نامعتبر است.']); return $warehouse; }
    private function rowCondition(array $row, Warehouse $warehouse): string { $condition=$row['item_condition'] ?? null; return in_array($condition,[SalesReturnDocumentItem::CONDITION_HEALTHY,SalesReturnDocumentItem::CONDITION_DAMAGED],true) ? $condition : $this->conditionForWarehouse($warehouse); }
    public function resolveDestinationWarehouse(string $condition, int $requestedId=0, bool $canOverride=false): Warehouse { $type=$condition===SalesReturnDocumentItem::CONDITION_HEALTHY?'central':'return'; if($canOverride && $requestedId>0){$w=Warehouse::whereKey($requestedId)->where('is_active',true)->whereIn('type',['central','return'])->first(); if($w)return $w;} return Warehouse::firstOrCreate(['type'=>$type,'name'=>$type==='central'?'انبار مرکزی':'انبار مرجوعی'],['is_active'=>true]); }

    public function materializeNewProductGroups(SalesReturnDocument $doc): void
    {
        $items = $doc->items->where('item_source', SalesReturnDocumentItem::SOURCE_NEW_PRODUCT);
        foreach ($items->groupBy(fn ($item) => (string) (($item->new_product_payload['temporary_product_uuid'] ?? '') ?: 'item-'.$item->id)) as $groupItems) {
            if ($groupItems->every(fn ($item) => $item->created_product_id && $item->created_variant_id && $item->product_variant_id)) continue;
            $first = $groupItems->first();
            $payload = $this->normalizer->normalize($first->new_product_payload ?: []);
            foreach(['name','category_id','purchase_price','sell_price'] as $k) if(!isset($payload[$k]) || $payload[$k]==='') throw ValidationException::withMessages(["items.$first->sort_order.new_product_payload.$k"=>'اطلاعات کالای جدید ناقص است.']);
            $cat = Category::query()->lockForUpdate()->find((int) $payload['category_id']);
            if (! $cat) throw ValidationException::withMessages(["items.$first->sort_order.new_product_payload.category_id" => 'دسته‌بندی نهایی کالا معتبر نیست.']);
            $seq = $this->nextProductSeq4();
            $productCode = $this->normalizeCategory2($cat->code).$seq;
            $product = Product::create(['category_id'=>$cat->id,'name'=>trim($payload['name']),'sku'=>'SR-'.now()->format('YmdHis').'-'.random_int(100,999),'code'=>$productCode,'short_barcode'=>$seq,'barcode'=>null,'stock'=>0,'reserved'=>0,'price'=>(int)($payload['sell_price']??0),'is_sellable'=>(bool)($payload['is_sellable']??true),'unit'=>$payload['unit']??'عدد','models'=>['sales_return_inline'=>true,'schema_version'=>2,'temporary_product_uuid'=>$payload['temporary_product_uuid']??null,'use_models'=>(bool)($payload['use_models']??false),'model_list_ids'=>$payload['model_list_ids']??[],'use_designs'=>(bool)($payload['use_designs']??false),'designs'=>$payload['designs']??[]]]);
            foreach ($groupItems as $item) {
                if ($item->created_variant_id && $item->product_variant_id) continue;
                $itemPayload = $this->normalizer->normalize($item->new_product_payload ?: $payload);
                $selected = collect($itemPayload['selected_variants'] ?? [])->first() ?: [];
                $model = !empty($selected['model_list_id']) ? ModelList::query()->lockForUpdate()->find((int) $selected['model_list_id']) : null;
                if (! empty($selected['model_list_id']) && ! $model) throw ValidationException::withMessages(["items.$item->sort_order.new_product_payload.model_list_ids" => 'مدل انتخاب‌شده معتبر نیست.']);
                $designIndex = (int)($selected['design_index'] ?? 0);
                $model3 = $model ? $this->normalizeModel3($model->code) : '000';
                $design2 = str_pad((string) max(0, min(99, $designIndex)), 2, '0', STR_PAD_LEFT);
                $variantCode = $this->buildVariantCode11($productCode, $model3, $design2);
                $variantName = trim((string)($selected['display_name'] ?? $itemPayload['variant_name'] ?? $payload['name']));
                $variant = ProductVariant::create(['product_id'=>$product->id,'model_list_id'=>$model?->id,'variant_name'=>$variantName,'variety_name'=>$variantName,'variety_code'=>'00'.$design2,'variant_code'=>$variantCode,'buy_price'=>(int)($payload['purchase_price']??0),'sell_price'=>(int)($payload['sell_price']??0),'stock'=>0,'reserved'=>0,'is_active'=>true,'sales_enabled'=>(bool)($payload['sales_enabled']??true)]);
                $item->update(['product_id'=>$product->id,'product_variant_id'=>$variant->id,'created_product_id'=>$product->id,'created_variant_id'=>$variant->id,'sku_snapshot'=>$variant->variant_code,'barcode_snapshot'=>$variant->variant_code,'variant_name_snapshot'=>$variant->variant_name]);
            }
        }
    }
    public function materializeNewProduct(SalesReturnDocumentItem $item): void { if($item->created_variant_id && $item->product_variant_id) return; $p=$item->new_product_payload ?: []; foreach(['product_name','variant_name','category_id','purchase_price','sell_price'] as $k) if(!isset($p[$k]) || $p[$k]==='') throw ValidationException::withMessages(["items.$item->sort_order.new_product_payload.$k"=>'اطلاعات کالای جدید ناقص است.']); $cat=Category::query()->lockForUpdate()->findOrFail((int)$p['category_id']); $seq=$this->nextProductSeq4(); $cat2=$this->normalizeCategory2($cat->code); $productCode=$cat2.$seq; $model=null; if(!empty($p['model_list_id'])) $model=ModelList::query()->lockForUpdate()->findOrFail((int)$p['model_list_id']); $model3=$model?$this->normalizeModel3($model->code):'000'; $varietyCode=preg_match('/^\d{4}$/',(string)($p['variety_code']??''))?(string)$p['variety_code']:'0000'; $design2=substr($varietyCode,-2); $variantCode=$p['sku'] ?: $this->buildVariantCode11($productCode,$model3,$design2); if(ProductVariant::where('variant_code',$variantCode)->exists()) throw ValidationException::withMessages(["items.$item->sort_order.new_product_payload.sku"=>'کد تنوع یا SKU تکراری است.']); if(!empty($p['barcode']) && ProductVariant::where('variant_code',$p['barcode'])->exists()) throw ValidationException::withMessages(["items.$item->sort_order.new_product_payload.barcode"=>'بارکد تکراری است.']); $product=Product::create(['category_id'=>$cat->id,'name'=>trim($p['product_name']),'sku'=>'SR-'.now()->format('YmdHis').'-'.random_int(100,999),'code'=>$productCode,'short_barcode'=>$seq,'barcode'=>$p['barcode']??null,'stock'=>0,'reserved'=>0,'price'=>(int)($p['sell_price']??0),'is_sellable'=>(bool)($p['sales_enabled']??true),'unit'=>$p['unit']??null,'models'=>['sales_return_inline'=>true,'model_list_id'=>$model?->id,'has_design'=>(bool)($p['has_design']??false),'has_color'=>(bool)($p['has_color']??false)]]); $variant=ProductVariant::create(['product_id'=>$product->id,'model_list_id'=>$model?->id,'variant_name'=>$p['variant_name'],'variety_name'=>$p['variety_name']??$p['variant_name'],'variety_code'=>$varietyCode,'variant_code'=>$variantCode,'buy_price'=>(int)($p['purchase_price']??0),'sell_price'=>(int)($p['sell_price']??0),'stock'=>0,'reserved'=>0,'is_active'=>(bool)($p['is_active']??true),'sales_enabled'=>(bool)($p['sales_enabled']??true)]); $item->update(['product_id'=>$product->id,'product_variant_id'=>$variant->id,'created_product_id'=>$product->id,'created_variant_id'=>$variant->id,'sku_snapshot'=>$variant->variant_code,'barcode_snapshot'=>$p['barcode']??$variant->variant_code]); }
    public function recordInventoryEntry(SalesReturnDocumentItem $item, ?int $actorId): void { if(StockMovement::where('reference_type',SalesReturnDocumentItem::class)->where('reference_id',$item->id)->exists()) return; $destinationWarehouseId=(int)($item->destination_warehouse_id ?: $item->document?->default_destination_warehouse_id); if($destinationWarehouseId<=0) throw ValidationException::withMessages(['items.'.$item->sort_order.'.destination_warehouse_id'=>'انبار مقصد ردیف نامعتبر است.']); $before=(int)WarehouseStock::where('warehouse_id',$destinationWarehouseId)->where('product_variant_id',$item->product_variant_id)->value('quantity'); WarehouseStockService::change($destinationWarehouseId,(int)$item->product_id,(int)$item->return_quantity,(int)$item->product_variant_id); $after=(int)WarehouseStock::where('warehouse_id',$destinationWarehouseId)->where('product_variant_id',$item->product_variant_id)->value('quantity'); StockMovement::create(['product_id'=>$item->product_id,'product_variant_id'=>$item->product_variant_id,'warehouse_id'=>$destinationWarehouseId,'user_id'=>$actorId ?: 1,'type'=>'in','reason'=>'sales_return','quantity'=>$item->return_quantity,'stock_before'=>$before,'stock_after'=>$after,'note'=>'برگشت از فروش '.$item->document->document_number,'reference'=>$item->document->document_number,'reference_type'=>SalesReturnDocumentItem::class,'reference_id'=>$item->id]); }
    public function recordCustomerCredit(SalesReturnDocument $doc, ?int $actorId): void { if($doc->total_refund_amount<=0)return; CustomerLedger::updateOrCreate(['customer_id'=>$doc->customer_id,'reference_type'=>SalesReturnDocument::class,'reference_id'=>$doc->id,'type'=>'credit'],['amount'=>$doc->total_refund_amount,'note'=>'بستانکاری بابت برگشت از فروش شماره '.$doc->document_number]); }

    private function assertInternalReturnablesStillValid(SalesReturnDocument $doc): void
    {
        $doc->items->load('invoiceItem');
        $ids = $doc->items->pluck('invoice_item_id')->filter()->values()->all();
        InvoiceItem::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();
        $previewRows = $doc->items->map(fn ($item) => [
            'invoice_item_id' => $item->invoice_item_id,
            'return_quantity' => $item->return_quantity,
        ])->all();
        $invoice = Invoice::with('items')->findOrFail((int) $doc->invoice_id);

        if ((int) $invoice->customer_id !== (int) $doc->customer_id) {
            throw ValidationException::withMessages(['invoice_id' => 'فاکتور متعلق به مشتری سند نیست.']);
        }

        $rows = $this->calculator->calculateInternalPreview($invoice, $previewRows);
        $byId = collect($rows)->keyBy(fn ($row) => (int) $row['invoice_item']->id);

        foreach ($doc->items as $idx => $item) {
            $row = $byId->get((int) $item->invoice_item_id);
            if (! $row || (int) $item->return_quantity > (int) $row['returnable_quantity']) {
                throw ValidationException::withMessages([
                    'items.'.$idx.'.return_quantity' => 'تعداد قابل برگشت در زمان ثبت نهایی تغییر کرده است.',
                ]);
            }

            $invoiceItem = $row['invoice_item'];
            $item->forceFill([
                'unit_price_snapshot' => (int) $invoiceItem->price,
                'line_discount_snapshot' => (int) ($invoiceItem->line_discount_amount ?? 0),
                'allocated_invoice_discount_snapshot' => (int) $row['allocated_discount'],
                'refund_unit_price' => (int) $row['refund_unit_price'],
                'refund_amount' => (int) $row['refund_amount'],
            ])->save();
        }
    }
    private function normalizeCategory2(?string $code): string { $c=trim((string)$code); if(!preg_match('/^\d{2}$/',$c)) throw ValidationException::withMessages(['category_id'=>'کد دسته‌بندی باید ۲ رقمی باشد.']); return $c; }
    private function normalizeModel3(?string $code): string { $c=preg_replace('/\D+/','',(string)$code); return str_pad(substr($c,0,3),3,'0',STR_PAD_LEFT); }
    private function nextProductSeq4(): string { $productMax=(int)DB::table('products')->selectRaw("MAX(CAST(COALESCE(NULLIF(short_barcode,''), SUBSTRING(code, 3, 4)) AS UNSIGNED)) as mx")->lockForUpdate()->value('mx'); $variantMax=(int)DB::table('product_variants')->whereNotNull('variant_code')->where('variant_code','<>','')->selectRaw("MAX(CAST(SUBSTRING(variant_code, 3, 4) AS UNSIGNED)) as mx")->lockForUpdate()->value('mx'); $next=max($productMax,$variantMax)+1; return str_pad((string)max(1,$next),4,'0',STR_PAD_LEFT); }
    private function buildVariantCode11(string $productCode6,string $model3,string $design2): string { $code=$productCode6.$model3.$design2; if(ProductVariant::where('variant_code',$code)->exists()) throw ValidationException::withMessages(['new_product_payload.sku'=>'کد یکی از تنوع‌های کالای جدید تکراری است.']); return $code; }
    private function refreshTotals(SalesReturnDocument $doc): void { $doc->load('items'); $doc->update(['items_count'=>$doc->items->count(),'total_quantity'=>$doc->items->sum('return_quantity'),'total_refund_amount'=>$doc->items->sum('refund_amount')]); }
    private function condition(?string $c): string { return in_array($c,[SalesReturnDocumentItem::CONDITION_HEALTHY,SalesReturnDocumentItem::CONDITION_DAMAGED],true)?$c:SalesReturnDocumentItem::CONDITION_HEALTHY; }
    private function conditionForWarehouse(Warehouse $warehouse): string { return $warehouse->type === 'return' ? SalesReturnDocumentItem::CONDITION_DAMAGED : SalesReturnDocumentItem::CONDITION_HEALTHY; }
    private function nextDocumentNumber(): string
    {
        $now = now();

        DB::table('document_sequences')->insertOrIgnore([
            'type' => 'sales_return',
            'last_number' => 0,
            'created_at' => $now,
            'updated_at' => $now,
        ]);

        $sequence = DB::table('document_sequences')
            ->where('type', 'sales_return')
            ->lockForUpdate()
            ->first();

        if (! $sequence) {
            throw new \RuntimeException('Sales return document sequence could not be initialized.');
        }

        $maxExisting = SalesReturnDocument::query()
            ->whereNotNull('document_number')
            ->pluck('document_number')
            ->map(function ($number) {
                $number = trim((string) $number);

                if (preg_match('/^\d+$/', $number) === 1) {
                    return (int) $number;
                }

                if (preg_match('/^SR-(\d+)$/', $number, $matches) === 1) {
                    return (int) $matches[1];
                }

                return 0;
            })
            ->max() ?? 0;

        $next = max((int) $sequence->last_number, (int) $maxExisting) + 1;

        DB::table('document_sequences')
            ->where('type', 'sales_return')
            ->update([
                'last_number' => $next,
                'updated_at' => $now,
            ]);

        return (string) $next;
    }
}
