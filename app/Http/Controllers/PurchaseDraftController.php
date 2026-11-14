<?php
namespace App\Http\Controllers;
use App\Models\PurchaseDraft;
use App\Services\PurchaseDraftService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
class PurchaseDraftController extends Controller {
    public function latest(Request $request,PurchaseDraftService $service){
        $draft=PurchaseDraft::where('user_id',$request->user()->id)->where('status','active')->whereNotNull('payload')->latest('updated_at')->first();
        return response()->json(['draft'=>$draft?$service->response($draft):null])->header('Cache-Control','no-store');
    }
    public function show(Request $request,string $token,PurchaseDraftService $service){
        $draft=PurchaseDraft::whereKey($token)->where('user_id',$request->user()->id)->firstOrFail();
        return response()->json(['draft'=>$service->response($draft)])->header('Cache-Control','no-store');
    }
    public function save(Request $request,PurchaseDraftService $service){
        $data=$request->validate(['token'=>['required','uuid'],'revision'=>['required','integer','min:0'],'draft_json'=>['required','string','max:2097152']]);
        try{$payload=json_decode($data['draft_json'],true,64,JSON_THROW_ON_ERROR);}catch(\JsonException $e){throw ValidationException::withMessages(['draft_json'=>'اطلاعات پیش‌نویس قابل خواندن نیست.']);}
        if(!is_array($payload)||($payload['version']??null)!==1||!is_array($payload['cards']??null)||count($payload['cards'])>1000)throw ValidationException::withMessages(['draft_json'=>'ساختار پیش‌نویس معتبر نیست.']);
        // Drafts are inert form data. Never run stock, price, supplier or ledger services here.
        return DB::transaction(function()use($data,$payload,$request,$service){
            $draft=$service->lock($data['token'],(int)$request->user()->id);
            if($draft->status!=='active'||$draft->revision!==(int)$data['revision'])return response()->json(['draft'=>$service->response($draft),'message'=>'نسخهٔ پیش‌نویس تغییر کرده است؛ اطلاعات این صفحه روی دستگاه محفوظ است.'],409);
            $draft->forceFill(['payload'=>$payload,'revision'=>$draft->revision+1])->save();
            return response()->json(['draft'=>$service->response($draft)])->header('Cache-Control','no-store');
        });
    }
    public function discard(Request $request,string $token,PurchaseDraftService $service){
        $data=$request->validate(['revision'=>['required','integer','min:0']]);
        return DB::transaction(function()use($token,$data,$request,$service){
            $draft=PurchaseDraft::whereKey($token)->where('user_id',$request->user()->id)->lockForUpdate()->firstOrFail();
            if($draft->status!=='active'||$draft->revision!==(int)$data['revision'])return response()->json(['draft'=>$service->response($draft)],409);
            $draft->forceFill(['status'=>'discarded','payload'=>null,'revision'=>$draft->revision+1])->save();
            return response()->json(['draft'=>$service->response($draft)]);
        });
    }
}
