<?php
namespace App\Services;
use App\Models\PurchaseDraft;
use Illuminate\Support\Facades\DB;
final class PurchaseDraftService {
    /** Must be called within the purchase/save transaction. Unique token serializes concurrent requests. */
    public function lock(string $token,int $userId): PurchaseDraft {
        DB::table('purchase_drafts')->insertOrIgnore(['token'=>$token,'user_id'=>$userId,'status'=>'active','revision'=>0,'created_at'=>now(),'updated_at'=>now()]);
        $draft=PurchaseDraft::whereKey($token)->lockForUpdate()->firstOrFail();
        abort_unless((int)$draft->user_id===$userId,404);
        return $draft;
    }
    public function response(PurchaseDraft $draft): array {
        return ['token'=>$draft->token,'revision'=>$draft->revision,'status'=>$draft->status,'payload'=>$draft->status==='active'?$draft->payload:null,
            'purchase_id'=>$draft->purchase_id,'purchase_url'=>$draft->purchase_id?route('purchases.show',$draft->purchase_id):null,'updated_at'=>$draft->updated_at?->toIso8601String()];
    }
}
