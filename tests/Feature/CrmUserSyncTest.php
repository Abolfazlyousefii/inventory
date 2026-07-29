<?php

use App\Models\IntegrationSyncState;
use App\Models\User;
use App\Services\CrmUserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['app.key' => 'base64:'.base64_encode(str_repeat('c', 32)), 'crm.sync_enabled' => true,
        'crm.base_url' => 'https://crm.example.test', 'crm.users_endpoint' => '/api/integrations/erp/users',
        'crm.sync.integration_token' => 'test-secret', 'crm.sync_limit' => 100, 'crm.sync.max_pages' => 10,
        'crm.sync_overlap_seconds' => 120, 'crm.sync.allow_initial_phone_link' => true,
        'crm.roles.mapping' => ['Marketer' => ['sales_user']], 'crm.roles.managed' => ['sales_user']]);
    Role::findOrCreate('sales_user', 'web'); Role::findOrCreate('auditor', 'web');
});

function crmUser(array $overrides = []): array {
    return array_replace(['id' => 10, 'name' => 'CRM User', 'phone' => '09120000010', 'email' => null,
        'manager_id' => null, 'roles' => ['Marketer'], 'is_active' => true, 'can_access_erp' => true,
        'is_seller' => true, 'created_at' => '2026-07-29T08:20:00Z', 'updated_at' => '2026-07-29T08:20:00Z'], $overrides);
}
function crmPage(array $users, bool $more = false, ?int $cursor = null): array {
    return ['data' => $users, 'next_cursor' => $cursor, 'has_more' => $more, 'meta' => ['schema_version' => 1]];
}

it('uses bearer auth canonical endpoint and follows cursor pages from zero', function () {
    Http::fake(function ($request) {
        expect($request->url())->toStartWith('https://crm.example.test/api/integrations/erp/users')
            ->and($request->hasHeader('Authorization', 'Bearer test-secret'))->toBeTrue();
        parse_str(parse_url($request->url(), PHP_URL_QUERY), $q);
        expect($q['include_inactive'])->toBe('true');
        return ($q['cursor'] ?? '0') === '0'
            ? Http::response(crmPage([crmUser(['id' => 11, 'phone' => '09120000011'])], true, 11))
            : Http::response(crmPage([crmUser(['id' => 12, 'phone' => '09120000012'])], false, 12));
    });
    $result = app(CrmUserService::class)->syncUsers(full: true);
    expect($result['error'])->toBeNull()->and($result['pages'])->toBe(2)
        ->and(User::whereNotNull('crm_user_id')->count())->toBe(2);
});

it('is idempotent updates phone by crm id and preserves password and local roles', function () {
    $password = Hash::make('existing-password');
    $user = User::factory()->create(['crm_user_id' => '10', 'phone' => '09121111111', 'password' => $password]);
    $user->assignRole('auditor');
    Http::fake(['*' => Http::response(crmPage([crmUser(['phone' => '09122222222'])]))]);
    app(CrmUserService::class)->syncUsers(full: true); app(CrmUserService::class)->syncUsers(full: true);
    $user->refresh();
    expect(User::where('crm_user_id', '10')->count())->toBe(1)->and($user->phone)->toBe('09122222222')
        ->and($user->password)->toBe($password)->and($user->hasRole('auditor'))->toBeTrue();
});

it('resolves managers after later pages and stores independent access and seller flags', function () {
    Http::fake(function ($request) {
        parse_str(parse_url($request->url(), PHP_URL_QUERY), $q);
        return ($q['cursor'] ?? '0') === '0'
            ? Http::response(crmPage([crmUser(['id'=>11,'phone'=>'09120000011','manager_id'=>10,'is_active'=>false,'can_access_erp'=>false,'is_seller'=>false])], true, 11))
            : Http::response(crmPage([crmUser(['id'=>10,'roles'=>[],'is_seller'=>false])], false, 10));
    });
    $result = app(CrmUserService::class)->syncUsers(full: true);
    $seller = User::where('crm_user_id', '11')->firstOrFail();
    expect($result['error'])->toBeNull()->and($result['managers_resolved'])->toBeGreaterThanOrEqual(1)
        ->and($seller->manager?->crm_user_id)->toBe('10')->and($seller->is_active)->toBeFalse()
        ->and($seller->can_access_erp)->toBeFalse()->and($seller->is_seller)->toBeFalse()
        ->and($result['managers_unresolved'])->toBe(0);
});

it('uses a fixed incremental window and resets cursor on each cycle', function () {
    IntegrationSyncState::create(['integration'=>'crm','stream'=>'users','last_succeeded_at'=>'2026-07-29 10:00:00']);
    $requests = [];
    Http::fake(function ($request) use (&$requests) { parse_str(parse_url($request->url(), PHP_URL_QUERY), $q); $requests[]=$q; return Http::response(crmPage([])); });
    app(CrmUserService::class)->syncUsers(full: false); app(CrmUserService::class)->syncUsers(full: false);
    expect($requests[0]['cursor'])->toBe('0')->and($requests[1]['cursor'])->toBe('0')
        ->and($requests[0]['updated_since'])->not->toBeEmpty()->and(IntegrationSyncState::value('last_succeeded_at'))->not->toBeNull();
});

it('does not advance successful state when the response contract is invalid', function () {
    $old = now()->subDay()->startOfSecond();
    IntegrationSyncState::create(['integration'=>'crm','stream'=>'users','last_succeeded_at'=>$old]);
    Http::fake(['*' => Http::response(['users'=>[],'has_more'=>false,'meta'=>['schema_version'=>1]])]);
    $result = app(CrmUserService::class)->syncUsers(full: false);
    expect($result['error'])->not->toBeNull()
        ->and(IntegrationSyncState::first()->last_succeeded_at->equalTo($old))->toBeTrue();
});

it('keeps dry run fully read only', function () {
    Http::fake(['*' => Http::response(crmPage([crmUser(['id'=>77])]))]);
    $result = app(CrmUserService::class)->syncUsers(dryRun:true, full:true);
    expect($result['error'])->toBeNull()->and(User::where('crm_user_id','77')->exists())->toBeFalse()
        ->and(IntegrationSyncState::query()->exists())->toBeFalse();
});

it('rejects a non advancing cursor and does not retry unauthorized responses', function () {
    Http::fakeSequence()->push(crmPage([], true, 0))->push([], 401);
    expect(app(CrmUserService::class)->syncUsers(full:true)['error'])->toBe('crm_invalid_pagination');
    Http::fake(['*' => Http::response([], 401)]);
    expect(app(CrmUserService::class)->syncUsers(full:true)['error'])->toBe('crm_unauthorized');
    Http::assertSentCount(1);
});

it('retries temporary failures but not contract failures', function () {
    Http::fakeSequence()->push([], 503)->push([], 503)->push(crmPage([]));
    expect(app(CrmUserService::class)->syncUsers(full:true)['error'])->toBeNull();
    Http::assertSentCount(3);
});

it('rejects html responses without retrying', function () {
    Http::fake(['*' => Http::response('<html>login</html>', 200, ['Content-Type'=>'text/html'])]);
    expect(app(CrmUserService::class)->syncUsers(full:true)['error'])->toBe('crm_invalid_response');
    Http::assertSentCount(1);
});

it('does not link ambiguous phone matches and never consumes password fields', function () {
    User::factory()->create(['crm_user_id'=>null,'phone'=>'09123334444']);
    User::factory()->create(['crm_user_id'=>null,'phone'=>'09123334444']);
    Http::fake(['*'=>Http::response(crmPage([crmUser(['id'=>44,'phone'=>'09123334444','password'=>'unsafe','password_hash'=>'unsafe'])]))]);
    expect(app(CrmUserService::class)->syncUsers(full:true)['error'])->toBe('crm_phone_ambiguous')
        ->and(User::where('crm_user_id','44')->exists())->toBeFalse();
});

it('blocks login when crm access is revoked without deleting the user', function () {
    $user = User::factory()->create(['phone'=>'09125556666','password'=>Hash::make('secret-pass'),'is_active'=>true,'can_access_erp'=>false]);
    $this->post('/login', ['phone'=>$user->phone,'password'=>'secret-pass'])->assertSessionHasErrors('phone');
    expect(User::whereKey($user->id)->exists())->toBeTrue();
});
