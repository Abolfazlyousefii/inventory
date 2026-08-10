<?php

use App\Models\Customer;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config(['services.external_sync.token' => 'shared-secret']);
});

it('registers a store user as a new inventory customer', function (): void {
    $response = $this->withHeaders([
        'Authorization' => 'Bearer shared-secret',
        'X-CRM-Token' => 'shared-secret',
    ])->postJson('/api/external/users', [
        'crm_user_id' => '42',
        'first_name' => 'علی',
        'last_name' => 'رضایی',
        'mobile' => '09121234567',
        'email' => 'ali@example.test',
        'status' => true,
        'password_hash' => 'must-not-be-stored',
    ]);

    $response->assertCreated()
        ->assertJsonPath('crm_user_id', '42');

    $customer = Customer::query()->sole();

    expect($customer->first_name)->toBe('علی')
        ->and($customer->last_name)->toBe('رضایی')
        ->and($customer->mobile)->toBe('09121234567')
        ->and($customer->sync_source)->toBe('store_registration')
        ->and($customer->reservation_tier)->toBe('new_or_low_purchase')
        ->and($customer->last_crm_payload)->not->toHaveKey('password_hash');
});

it('updates the same customer instead of creating a duplicate', function (): void {
    Customer::query()->create([
        'first_name' => 'قدیمی',
        'mobile' => '09121234567',
    ]);

    $this->withHeader('X-CRM-Token', 'shared-secret')
        ->postJson('/api/external/users', [
            'crm_user_id' => '42',
            'first_name' => 'علی',
            'last_name' => 'رضایی',
            'username' => '09121234567',
        ])
        ->assertOk();

    expect(Customer::query()->count())->toBe(1)
        ->and(Customer::query()->first()->crm_customer_id)->toBe('42');
});

it('rejects requests with an invalid token', function (): void {
    $this->withHeader('X-CRM-Token', 'wrong-token')
        ->postJson('/api/external/users', [
            'crm_user_id' => '42',
            'mobile' => '09121234567',
        ])
        ->assertUnauthorized();

    expect(Customer::query()->count())->toBe(0);
});
