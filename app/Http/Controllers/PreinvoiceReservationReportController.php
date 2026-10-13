<?php
namespace App\Http\Controllers;

use App\Models\{Category, PreinvoiceDraftReservation};
use App\Services\PreinvoiceReservationReportService;
use Illuminate\Http\Request;

class PreinvoiceReservationReportController extends Controller
{
    public function index(Request $request, PreinvoiceReservationReportService $report)
    {
        $filters=$request->validate(['q'=>['nullable','string','max:150'],'seller_id'=>['nullable','integer','exists:users,id'],'category_id'=>['nullable','integer','exists:categories,id']]);
        $query=$report->rows($filters);$summary=$report->summary($query);
        $rows=$query->orderByDesc('reserved_at')->orderBy('o.id')->orderBy('r.product_id')->orderBy('r.variant_id')->paginate(30)->appends($filters);
        if($request->expectsJson())return response()->json(['summary'=>$summary,'rows'=>$rows]);
        return view('warehouse-reservations.preinvoice-report',['rows'=>$rows,'summary'=>$summary,'filters'=>$filters,'sellers'=>$report->sellers(),'categories'=>Category::orderBy('name')->get(['id','name','parent_id']),'evaluatedAt'=>now()]);
    }

    public function show(PreinvoiceDraftReservation $reservation)
    {
        return redirect()->route('warehouse-reservations.index',['q'=>$reservation->order?->uuid]);
    }
}
