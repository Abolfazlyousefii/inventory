<?php

namespace App\Services;

use App\Models\{ProductVariant, Warehouse};
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

class ShowroomCatalogService
{
    public function destination(): Warehouse
    {
        return Warehouse::where('type','showroom')->where('is_active',true)->orderBy('id')->firstOrFail();
    }

    public function query(int $centralId, int $showroomId, bool $activeOnly=true): Builder
    {
        $stocks=DB::table('warehouse_stocks')->whereIn('warehouse_id',[$centralId,$showroomId])
            ->whereNotNull('product_variant_id')->groupBy('product_id','product_variant_id')
            ->select('product_id','product_variant_id')
            ->selectRaw('SUM(CASE WHEN warehouse_id = ? THEN quantity ELSE 0 END) AS central_available',[$centralId])
            ->selectRaw('SUM(CASE WHEN warehouse_id = ? THEN quantity ELSE 0 END) AS showroom_quantity',[$showroomId]);
        return ProductVariant::query()->leftJoinSub($stocks,'showroom_stocks',fn($join)=>$join
            ->on('showroom_stocks.product_variant_id','=','product_variants.id')->on('showroom_stocks.product_id','=','product_variants.product_id'))
            ->whereHas('product',fn($q)=>$q->when($activeOnly,fn($q)=>$q->where('is_sellable',true)))
            ->when($activeOnly,fn($q)=>$q->where('product_variants.is_active',true))
            ->with(['product:id,name,category_id,sku,code,short_barcode,is_sellable','modelList:id,model_name','color:id,name'])
            ->select('product_variants.*')->selectRaw('COALESCE(showroom_stocks.central_available,0) AS central_available, COALESCE(showroom_stocks.showroom_quantity,0) AS showroom_quantity');
    }

    public function row(ProductVariant $variant): array
    {
        return ['id'=>(int)$variant->id,'product_id'=>(int)$variant->product_id,'category_id'=>(int)$variant->product->category_id,
            'product_name'=>(string)$variant->product->name,
            'variant_title'=>collect([$variant->variant_name,$variant->modelList?->model_name,$variant->color?->name,$variant->variety_name])->filter()->unique()->implode(' / '),
            'group_key'=>($variant->model_list_id??0).':'.($variant->color_id??0).':'.($variant->model_list_id||$variant->color_id?'':$variant->variant_name),
            'group_title'=>collect([$variant->modelList?->model_name,$variant->color?->name])->filter()->implode(' / ') ?: ($variant->variant_name ?: 'تنوع اصلی'),
            'subvariant_title'=>$variant->variety_name ?: ($variant->variety_code && $variant->variety_code!=='0000' ? 'طرح '.$variant->variety_code : ''),
            'code'=>(string)($variant->variant_code ?: $variant->variety_code ?: ''),
            'product_code'=>(string)($variant->product->short_barcode ?: $variant->product->code ?: $variant->product->sku ?: ''),
            'available'=>(int)$variant->central_available,'showroom_quantity'=>(int)$variant->showroom_quantity,
            'selectable'=>(bool)$variant->is_active && (bool)$variant->product->is_sellable && (int)$variant->central_available>0];
    }
}
