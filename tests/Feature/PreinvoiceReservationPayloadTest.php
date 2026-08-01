<?php

use App\Http\Middleware\RoutePermissionMiddleware;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware(RoutePermissionMiddleware::class);
});

it('syncs compact json reservation payloads idempotently', function (int $variantCount) {
    $role=Role::findOrCreate('preinvoice-reservation-sync','web'); $role->givePermissionTo(Permission::findOrCreate('preinvoices.create','web'));
    $user=largePayloadUser(); $user->assignRole($role); $this->actingAs($user);
    $rows=largePayloadProducts($variantCount); $token=(string)Str::uuid();
    $items=collect($rows)->map(fn($row)=>['product_id'=>$row['product_id'],'variant_id'=>$row['variant_id'],'quantity'=>1])->all();
    $payload=['reservation_token'=>$token,'submission_token'=>$token,'items'=>$items,'is_in_person'=>false];
    $this->postJson(route('preinvoice.api.reservations.sync'),$payload)->assertOk()->assertJsonCount($variantCount,'data.reserved');
    $this->postJson(route('preinvoice.api.reservations.sync'),$payload)->assertOk()->assertJsonCount($variantCount,'data.reserved');
    $this->assertDatabaseCount('preinvoice_draft_reservations',$variantCount);
})->with([1,20,50,100]);
