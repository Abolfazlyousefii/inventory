<?php

namespace Tests\Feature;

use App\Models\PreinvoiceOrder;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MyPreinvoicesSellerScopeTest extends TestCase
{
    use RefreshDatabase;

    public function test_scope_uses_internal_seller_id_not_creator_or_crm_id(): void
    {
        $seller = User::factory()->create(['crm_user_id' => '90001']);
        $creator = User::factory()->create();
        $base = ['status' => 'draft', 'customer_name' => 'Test', 'customer_mobile' => '09120000000', 'customer_address' => 'Test', 'province_id' => 1];
        $owned = PreinvoiceOrder::query()->create($base + ['uuid' => fake()->uuid(), 'created_by' => $creator->id, 'seller_id' => $seller->id]);
        PreinvoiceOrder::query()->create($base + ['uuid' => fake()->uuid(), 'created_by' => $seller->id, 'seller_id' => $creator->id]);

        $this->assertSame([$owned->id], PreinvoiceOrder::query()->createdBySeller($seller->id)->pluck('id')->all());
    }
}
