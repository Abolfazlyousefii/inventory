<?php
namespace App\Services;

use App\Models\{Category, PreinvoiceOrder, User};
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/** Only observes official held reservations. Never expires or releases them. */
class PreinvoiceReservationReportService
{
    public function rows(array $filters = []): Builder
    {
        $query=DB::table('preinvoice_draft_reservations as r')
            ->join('preinvoice_orders as o','o.id','=','r.preinvoice_order_id')
            ->leftJoin('products as p','p.id','=','r.product_id')
            ->leftJoin('product_variants as v',function($j){$j->on('v.id','=','r.variant_id')->on('v.product_id','=','r.product_id');})
            ->leftJoin('users as u','u.id','=',DB::raw('COALESCE(o.seller_id, o.created_by)'))
            ->where('r.reservation_scope','official')->whereNull('r.converted_at')->whereNull('r.released_at')
            ->whereNull('r.release_reason')->where('r.quantity','>',0)
            ->whereIn('o.status',PreinvoiceOrder::reservationHoldingStatuses())
            ->whereNotExists(fn($q)=>$q->selectRaw('1')->from('invoices as i')->whereColumn('i.preinvoice_order_id','o.id'));
        if(!empty($filters['seller_id'])) $query->whereRaw('COALESCE(o.seller_id,o.created_by) = ?',[(int)$filters['seller_id']]);
        if(!empty($filters['category_id'])) $query->whereIn('p.category_id',Category::selfAndDescendantIds((int)$filters['category_id']));
        if(!empty($filters['q'])) {
            $term='%'.addcslashes(trim($filters['q']),'%_\\').'%';
            $query->where(function($q)use($term){foreach(['o.uuid','o.customer_name','o.customer_mobile','p.name','v.variant_name','v.variant_code']as$c)$q->orWhere($c,'like',$term);});
        }
        return $query->select(['o.id as order_id','o.uuid','o.customer_name','o.customer_mobile','o.status','o.stock_frozen_until','r.product_id','r.variant_id','p.name as product_name','v.variant_name','v.variant_code','u.name as seller_name'])
            ->selectRaw('COALESCE(o.seller_id,o.created_by) seller_id, SUM(r.quantity) quantity, MIN(r.created_at) reserved_at, MIN(r.expires_at) expires_at')
            ->groupBy('o.id','o.uuid','o.customer_name','o.customer_mobile','o.status','o.stock_frozen_until','o.seller_id','o.created_by','r.product_id','r.variant_id','p.name','v.variant_name','v.variant_code','u.name');
    }

    public function summary(Builder $rows): object
    {
        return DB::query()->fromSub(clone $rows,'held')->selectRaw('COUNT(*) rows_count, COUNT(DISTINCT order_id) orders_count, COALESCE(SUM(quantity),0) quantity')->first();
    }

    public function sellers()
    {
        return User::activeErpUsers()->select('id as seller_id','name as seller_name')->orderBy('name')->get();
    }
}
