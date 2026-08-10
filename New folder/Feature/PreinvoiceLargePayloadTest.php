<?php

use App\Models\PreinvoiceDraftReservation;
use App\Models\PreinvoiceOrder;
use App\Http\Middleware\RoutePermissionMiddleware;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;

require_once __DIR__.'/../Support/LargePayloadFixtures.php';

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(RoutePermissionMiddleware::class);
});

it('submits 220 products through a complete json payload without products 198 validation loss', function () {
    $user = largePayloadUser(); $rows = largePayloadProducts(220);
    $gross = collect($rows)->sum(fn ($row) => (int) $row['quantity'] * (int) $row['price']);
    $this->actingAs($user)->post(route('preinvoice.draft.save'), largePayloadPost($rows))->assertSessionHasNoErrors();
    $order = PreinvoiceOrder::query()->where('created_by', $user->id)->latest('id')->firstOrFail();
    expect($order->status)->toBe(PreinvoiceOrder::STATUS_PENDING_FINANCE)->and($order->items()->count())->toBe(220)
        ->and((int) $order->items()->sum('quantity'))->toBe(440)->and((int) $order->total_price)->toBe($gross)
        ->and(PreinvoiceDraftReservation::query()->where('preinvoice_order_id', $order->id)->where('reservation_scope', 'official')->count())->toBe(220);
});

it('updates a large draft without deleting tail items and allows intentional deletion only', function () {
    $user = largePayloadUser(); $order = largePayloadOrder($user, largePayloadProducts(220));
    $rows = $order->items->map(fn ($item) => ['item_id'=>$item->id,'id'=>$item->product_id,'product_id'=>$item->product_id,
        'variety_id'=>$item->variant_id,'variant_id'=>$item->variant_id,'quantity'=>$item->quantity,'price'=>$item->price,'line_discount_amount'=>0])->values()->all();
    $beforeIds=$order->items->pluck('id')->all(); $beforeTotal=(int)$order->total_price;
    $this->actingAs($user)->put(route('preinvoice.draft.update',$order->uuid),largePayloadPost($rows,['intent'=>'draft']))->assertSessionHasNoErrors();
    $order->refresh()->load('items'); expect($order->items)->toHaveCount(220)->and($order->items->pluck('id')->all())->toBe($beforeIds)->and((int)$order->total_price)->toBe($beforeTotal);
    $deleted=array_pop($rows); $this->actingAs($user)->put(route('preinvoice.draft.update',$order->uuid),largePayloadPost($rows,['intent'=>'draft']))->assertSessionHasNoErrors();
    $order->refresh()->load('items'); expect($order->items)->toHaveCount(219)->and($order->items->pluck('id')->contains($deleted['item_id']))->toBeFalse();
});

it('rejects incomplete json payloads before mutating an existing draft', function (string $case) {
    $user=largePayloadUser(); $rows=largePayloadProducts(220); $order=largePayloadOrder($user,$rows);
    $beforeTotal=(int)$order->total_price; $beforeStatus=$order->status; $beforeItems=$order->items()->count(); $beforeReservations=PreinvoiceDraftReservation::query()->where('preinvoice_order_id',$order->id)->count();
    $override=match($case){'count mismatch'=>['products_payload'=>json_encode(array_slice($rows,0,219),JSON_THROW_ON_ERROR),'products_payload_count'=>220],
        'broken json'=>['products_payload'=>'{bad','products_payload_count'=>220],'not complete'=>['products_payload_complete'=>0],
        'empty payload'=>['products_payload'=>''],'missing id'=>['products_payload'=>json_encode([['variety_id'=>1,'quantity'=>1,'price'=>1]],JSON_THROW_ON_ERROR),'products_payload_count'=>1],
        'missing variety'=>['products_payload'=>json_encode([['id'=>1,'quantity'=>1,'price'=>1]],JSON_THROW_ON_ERROR),'products_payload_count'=>1],
        'missing quantity'=>['products_payload'=>json_encode([['id'=>1,'variety_id'=>1,'price'=>1]],JSON_THROW_ON_ERROR),'products_payload_count'=>1]};
    $this->actingAs($user)->from(route('preinvoice.draft.edit',$order->uuid))->put(route('preinvoice.draft.update',$order->uuid),largePayloadPost($rows,$override))->assertSessionHasErrors();
    $order->refresh(); expect($order->items()->count())->toBe($beforeItems)->and((int)$order->total_price)->toBe($beforeTotal)->and($order->status)->toBe($beforeStatus)
        ->and(PreinvoiceDraftReservation::query()->where('preinvoice_order_id',$order->id)->count())->toBe($beforeReservations);
})->with(['count mismatch','broken json','not complete','empty payload','missing id','missing variety','missing quantity']);

it('renders the json payload contract instead of relying on legacy product inputs during submit', function () {
    $view=File::get(resource_path('views/preinvoice/create.blade.php'));
    expect($view)->toContain('name="products_payload"')->and($view)->toContain('name="products_payload_count"')->and($view)->toContain('name="products_payload_complete"')
        ->and($view)->toContain('collectProductsForSubmit')->and($view)->toContain('prepareProductsPayloadForSubmit')->and($view)->toContain('JSON.stringify')->and($view)->toContain('disable legacy product inputs');
});
