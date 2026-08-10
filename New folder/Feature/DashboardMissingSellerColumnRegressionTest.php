<?php

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

it('returns a controlled dashboard with zero seller counts before the ownership migration', function () {
    $role=Role::findOrCreate('Owner','web');
    $user=User::factory()->create(['is_seller'=>true]); $user->assignRole($role);
    Schema::table('preinvoice_orders',function(Blueprint $table){$table->dropForeign(['seller_id']);$table->dropIndex('preinvoice_orders_seller_id_index');$table->dropColumn('seller_id');});
    $this->actingAs($user)->get(route('dashboard'))->assertOk()->assertSee('0');
});
