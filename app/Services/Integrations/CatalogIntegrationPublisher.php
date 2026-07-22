<?php
namespace App\Services\Integrations;
use App\Jobs\Integrations\DeliverAriyaOutboxEventJob; use App\Models\Integration\IntegrationOutboxEvent; use App\Models\ProductVariant; use Illuminate\Support\Facades\DB; use Illuminate\Support\Str;
class CatalogIntegrationPublisher {
 public function publishVariant(ProductVariant $variant, string $type='catalog.variant.updated'): IntegrationOutboxEvent {
  $variant->loadMissing('product');
  $payload=['event_id'=>(string)Str::uuid(),'event_type'=>$type,'occurred_at'=>now()->toIso8601String(),'variant'=>$this->variantPayload($variant)];
  $event=IntegrationOutboxEvent::create(['event_id'=>$payload['event_id'],'destination'=>'ariya_site','event_type'=>$type,'aggregate_type'=>ProductVariant::class,'aggregate_id'=>$variant->id,'payload'=>$payload,'status'=>'pending','available_at'=>now()]);
  DB::afterCommit(fn()=>DeliverAriyaOutboxEventJob::dispatch($event->id)->onQueue(config('ariya_site.queue','integrations')));
  return $event;
 }
 public function variantPayload(ProductVariant $v): array { return ['external_variant_id'=>(string)($v->variety_id ?: $v->variant_code ?: $v->id),'product_id'=>(int)$v->product_id,'variant_id'=>(int)$v->id,'variant_code'=>$v->variant_code,'sku'=>$v->variant_code,'is_active'=>(bool)$v->is_active,'is_sellable'=>(bool)$v->sales_enabled && (bool)$v->is_active,'stock'=>max(0,(int)$v->stock),'reserved'=>max(0,(int)$v->reserved),'sell_price'=>max(0,(int)$v->sell_price),'version'=>(int)$v->updated_at?->timestamp,'updated_at'=>$v->updated_at?->toIso8601String()]; }
}
