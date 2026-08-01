<?php

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\CrmUserService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function crmSsoUser(array $overrides = []): array
{
    return array_replace([
        'id' => 18, 'name' => 'CRM User', 'phone' => '09120000018', 'email' => null,
        'manager_id' => null, 'roles' => ['Sales'], 'is_active' => true,
        'can_access_erp' => true, 'is_seller' => true,
        'created_at' => '2026-07-29T08:20:00Z', 'updated_at' => '2026-07-29T08:20:00Z',
    ], $overrides);
}

beforeEach(function () {
    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('s', 32)),
        'crm.base_url' => 'https://crm.ariyajanebi.ir',
        'crm.sso.enabled' => true,
        'crm.sso.client_id' => 'erp-test-client',
        'crm.sso.client_secret' => 'test-secret-never-in-url',
        'crm.sso.redirect_uri' => 'https://inv.ariyajanebi.ir/auth/crm/callback',
        'crm.sso.authorize_url' => 'https://crm.ariyajanebi.ir/oauth/authorize',
        'crm.sso.token_url' => 'https://crm.ariyajanebi.ir/oauth/token',
        'crm.sso.user_url' => 'https://crm.ariyajanebi.ir/api/integrations/erp/me',
        'crm.sso.scope' => 'erp.user.read',
        'crm.sso.pkce_enabled' => true,
        'crm.roles.mapping' => ['Sales' => ['sales_user']],
        'crm.roles.managed' => ['sales_user'],
    ]);
    Role::findOrCreate('sales_user', 'web');
});

it('creates a state and PKCE challenge and redirects guests without leaking secrets', function () {
    $response = $this->get(route('auth.crm.redirect'));

    $response->assertRedirect();
    $location = $response->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($location)->toStartWith('https://crm.ariyajanebi.ir/oauth/authorize?')
        ->not->toContain('test-secret-never-in-url')
        ->and($query['client_id'])->toBe('erp-test-client')
        ->and($query['redirect_uri'])->toBe('https://inv.ariyajanebi.ir/auth/crm/callback')
        ->and($query['response_type'])->toBe('code')
        ->and($query['code_challenge_method'])->toBe('S256')
        ->and($query['state'])->toBeString()->not->toBeEmpty();

    $response->assertSessionHas('crm_oauth_state', $query['state'])
        ->assertSessionHas('crm_oauth_code_verifier');
});

it('rejects callbacks with missing or mismatched state and missing code', function () {
    $this->get(route('auth.crm.callback'))->assertRedirect(route('login'))->assertSessionHasErrors('crm');

    $this->withSession(['crm_oauth_state' => 'expected'])
        ->get(route('auth.crm.callback', ['state' => 'wrong', 'code' => 'code']))
        ->assertRedirect(route('login'))->assertSessionHasErrors('crm');

    $this->withSession(['crm_oauth_state' => 'expected'])
        ->get(route('auth.crm.callback', ['state' => 'expected']))
        ->assertRedirect(route('login'))->assertSessionHasErrors('crm');
});

it('exchanges the code, upserts by crm id, maps roles and logs in without copying a password hash', function () {
    Http::fake([
        'https://crm.ariyajanebi.ir/oauth/token' => Http::response([
            'access_token' => 'user-access-token', 'token_type' => 'Bearer', 'expires_in' => 600,
        ]),
        'https://crm.ariyajanebi.ir/api/integrations/erp/me' => Http::response(['data' => crmSsoUser([
            'name' => 'کاربر فروش',
            'email' => 'sales18@example.test',
            'password_hash' => '$2y$10$must-never-be-copied',
        ])]),
    ]);

    $redirect = $this->get(route('auth.crm.redirect'));
    parse_str((string) parse_url($redirect->headers->get('Location'), PHP_URL_QUERY), $query);
    $oldSessionId = session()->getId();

    $this->get(route('auth.crm.callback', ['state' => $query['state'], 'code' => 'one-time-code']))
        ->assertRedirect(route('access.unassigned'));

    $user = User::query()->where('crm_user_id', '18')->firstOrFail();
    $this->assertAuthenticatedAs($user);
    expect($user->phone)->toBe('09120000018')
        ->and($user->is_crm_managed)->toBeTrue()
        ->and($user->hasRole('sales_user'))->toBeTrue()
        ->and($user->password)->not->toBe('$2y$10$must-never-be-copied')
        ->and(session()->getId())->not->toBe($oldSessionId)
        ->and(ActivityLog::query()->get()->toJson())
        ->not->toContain('user-access-token', 'one-time-code', 'test-secret-never-in-url');

    Http::assertSent(fn ($request) => $request->url() === 'https://crm.ariyajanebi.ir/oauth/token'
        && $request['grant_type'] === 'authorization_code'
        && filled($request['code_verifier']));
    Http::assertSent(fn ($request) => $request->url() === 'https://crm.ariyajanebi.ir/api/integrations/erp/me'
        && $request->hasHeader('Authorization', 'Bearer user-access-token'));
});

it('updates a changed phone on the same crm user and refuses inactive or unmapped users', function () {
    $user = User::factory()->create([
        'crm_user_id' => '18',
        'is_crm_managed' => true,
        'phone' => '09121111111',
        'is_active' => true,
    ]);

    $service = app(CrmUserService::class);
    $result = $service->syncOnePayload(crmSsoUser([
        'name' => 'نام جدید', 'phone' => '09122222222',
    ]));

    expect($result['user']->id)->toBe($user->id)
        ->and(User::query()->where('crm_user_id', '18')->count())->toBe(1)
        ->and($user->fresh()->phone)->toBe('09122222222');

    $inactive = $service->syncOnePayload(crmSsoUser([
        'id' => 19, 'name' => 'غیرفعال', 'phone' => '09120000019',
        'is_active' => false,
    ]))['user'];
    expect($inactive->is_active)->toBeFalse();

    $unmapped = $service->syncOnePayload(crmSsoUser([
        'id' => 20, 'name' => 'بدون نقش', 'phone' => '09120000020',
        'roles' => ['UnknownPowerRole'],
    ]))['user'];
    expect($unmapped->roles)->toBeEmpty();
});

it('fails closed when token exchange fails or the CRM user is inactive', function () {
    Http::fake(['https://crm.ariyajanebi.ir/oauth/token' => Http::response(['error' => 'invalid_grant'], 400)]);
    $redirect = $this->get(route('auth.crm.redirect'));
    parse_str((string) parse_url($redirect->headers->get('Location'), PHP_URL_QUERY), $query);

    $this->get(route('auth.crm.callback', ['state' => $query['state'], 'code' => 'bad-code']))
        ->assertRedirect(route('login'))->assertSessionHasErrors('crm');
    $this->assertGuest();

    Http::fake([
        'https://crm.ariyajanebi.ir/oauth/token' => Http::response(['access_token' => 'inactive-token']),
        'https://crm.ariyajanebi.ir/api/integrations/erp/me' => Http::response(['data' => crmSsoUser([
            'id' => 44, 'name' => 'کاربر غیرفعال', 'phone' => '09120000044',
            'is_active' => false,
        ])]),
    ]);
    $redirect = $this->get(route('auth.crm.redirect'));
    parse_str((string) parse_url($redirect->headers->get('Location'), PHP_URL_QUERY), $query);

    $this->get(route('auth.crm.callback', ['state' => $query['state'], 'code' => 'valid-code']))
        ->assertRedirect(route('login'))->assertSessionHasErrors('crm');
    $this->assertGuest();
});

it('allows active local emergency login but blocks inactive and crm-managed local login when the transition flag is off', function () {
    config(['crm.sso.local_login_for_managed_users' => false]);
    $local = User::factory()->create(['phone' => '09120000001', 'password' => Hash::make('StrongPass!1'), 'is_active' => true]);
    $managed = User::factory()->create(['phone' => '09120000002', 'password' => Hash::make('StrongPass!1'), 'crm_user_id' => '2', 'is_crm_managed' => true, 'is_active' => true]);
    $inactive = User::factory()->create(['phone' => '09120000003', 'password' => Hash::make('StrongPass!1'), 'is_active' => false]);

    $this->post(route('login'), ['phone' => $local->phone, 'password' => 'StrongPass!1'])->assertRedirect(route('access.unassigned'));
    $this->assertAuthenticatedAs($local);
    auth()->logout();

    $this->post(route('login'), ['phone' => $managed->phone, 'password' => 'StrongPass!1'])->assertSessionHasErrors('phone');
    $this->assertGuest();
    $this->post(route('login'), ['phone' => $inactive->phone, 'password' => 'StrongPass!1'])->assertSessionHasErrors('phone');
    $this->assertGuest();
});
