<?php

namespace App\Http\Controllers;

use App\Models\{Category, Product};
use App\Services\{ProductSearchService, ShowroomCatalogService, WarehouseStockService};
use App\Support\WarehouseVoucherAccess;
use Illuminate\Http\Request;

class ShowroomCatalogController extends Controller
{
    public function __invoke(Request $request, ShowroomCatalogService $catalog)
    {
        abort_if(WarehouseVoucherAccess::restricted($request->user()),403);
        $data=$request->validate(['q'=>['nullable','string','max:100'],'category_id'=>['nullable','integer','exists:categories,id'],
            'product_id'=>['nullable','integer','exists:products,id'],'view'=>['nullable','in:products,variants'],
            'cursor'=>['nullable','integer','min:0'],'limit'=>['nullable','integer','min:1','max:50'],'available_only'=>['nullable','boolean'],
            'ids'=>['nullable','array','min:1','max:100'],'ids.*'=>['integer','distinct','min:1']]);
        $limit=(int)($data['limit']??30);
        $ids=$data['ids']??[];
        $query=$catalog->query(WarehouseStockService::centralWarehouseId(),$catalog->destination()->id,!$ids)
            ->when($ids,fn($q)=>$q->whereIn('product_variants.id',$ids))
            ->when($data['category_id']??null,fn($q,$id)=>$q->whereHas('product',fn($p)=>$p->whereIn('category_id',Category::selfAndDescendantIds((int)$id))))
            ->when(!$ids && $request->boolean('available_only',true),fn($q)=>$q->whereRaw('COALESCE(showroom_stocks.central_available,0)>0'))
            ->when($data['product_id']??null,fn($q,$id)=>$q->where('product_variants.product_id',$id));
        $term=ProductSearchService::normalize($data['q']??'');
        foreach(preg_split('/\s+/u',$term,-1,PREG_SPLIT_NO_EMPTY) as $word) {
            $query->where(fn($q)=>$q->whereHas('product',fn($p)=>$p->where(fn($p)=>$p
                ->where('name','like','%'.$word.'%')->orWhere('sku','like','%'.$word.'%')->orWhere('code','like','%'.$word.'%')->orWhere('short_barcode','like','%'.$word.'%')))
                ->orWhere('variant_name','like','%'.$word.'%')->orWhere('variant_code','like','%'.$word.'%')->orWhere('variety_name','like','%'.$word.'%')->orWhere('variety_code','like','%'.$word.'%')
                ->orWhereHas('modelList',fn($model)=>$model->where('model_name','like','%'.$word.'%'))->orWhereHas('color',fn($color)=>$color->where('name','like','%'.$word.'%')));
        }
        if (($data['view']??'variants')==='products' && !$ids) {
            $products=Product::whereIn('id',$query->reorder()->select('product_variants.product_id'))
                ->where('id','>',(int)($data['cursor']??0))->orderBy('id')->limit($limit+1)->get(['id','name','category_id']);
            $more=$products->count()>$limit;$page=$products->take($limit);
            return response()->json(['data'=>$page->values(),'meta'=>['has_more'=>$more,'next_cursor'=>$more?(int)$page->last()->id:null]]);
        }
        $found=$query->where('product_variants.id','>',(int)($data['cursor']??0))->orderBy('product_variants.id')->limit($ids ? 100 : $limit+1)->get();$more=!$ids && $found->count()>$limit;$page=$ids ? $found : $found->take($limit);
        return response()->json(['data'=>$page->map(fn($v)=>$catalog->row($v))->values(),
            'meta'=>['has_more'=>$more,'next_cursor'=>$more ? (int)$page->last()->id : null]]);
    }
}
