<?php

use App\Models\ActivityLog;
use App\Models\User;
use App\Services\Crm\TokenService;
use App\Support\FirstAllowedPageResolver;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Monolog\Handler\TestHandler;
use Monolog\Logger;

uses(RefreshDatabase::class);

function phoneTokenUser(array $overrides = []): User
{
    return User::factory()->create(array_replace([
        'phone' => '09120000018',
        'is_active' => true,
        'can_access_erp' => true,
        'remember_token' => null,
    ], $overrides));
}

function validPhoneToken(string $phone): string
{
    return app(TokenService::class)->hash($phone)['token'];
}

afterEach(function (): void {
    Carbon::setTestNow();
});

it('registers only the active phone token login route', function (): void {
    expect(Route::has('auth.phone.verify'))->toBeTrue()
        ->and(route('auth.phone.verify', absolute: false))->toBe('/auth/phone/verify')
        ->and(Route::has('auth.crm.redirect'))->toBeFalse()
        ->and(Route::has('auth.crm.callback'))->toBeFalse();
});

it('requires both phone and token', function (array $query, string $missing): void {
    $this->get(route('auth.phone.verify', $query))
        ->assertRedirect()
        ->assertSessionHasErrors($missing);
})->with([
    'missing phone' => [['token' => str_repeat('a', 32)], 'phone'],
    'missing token' => [['phone' => '09120000018'], 'token'],
]);

it('rejects invalid and expired tokens with 403', function (string $token): void {
    Carbon::setTestNow('2026-08-16 12:00:00');
    $phone = '09120000018';

    if ($token === 'expired') {
        $token = validPhoneToken($phone);
        Carbon::setTestNow('2026-08-16 12:10:00');
    }

    $this->get(route('auth.phone.verify', compact('phone', 'token')))
        ->assertForbidden();
    $this->assertGuest();
})->with([
    'invalid token' => [str_repeat('f', 32)],
    'expired token' => ['expired'],
]);

it('returns a controlled validation error for a valid token with an unknown phone', function (): void {
    Carbon::setTestNow('2026-08-16 12:00:00');
    $phone = '09120000099';

    $this->get(route('auth.phone.verify', [
        'phone' => $phone,
        'token' => validPhoneToken($phone),
    ]))->assertRedirect()->assertSessionHasErrors('phone');

    $this->assertGuest();
});

it('denies inactive users and users without ERP access', function (array $attributes): void {
    Carbon::setTestNow('2026-08-16 12:00:00');
    $user = phoneTokenUser($attributes);

    $this->get(route('auth.phone.verify', [
        'phone' => $user->phone,
        'token' => validPhoneToken($user->phone),
    ]))->assertRedirect()->assertSessionHasErrors('phone');

    $this->assertGuest();
})->with([
    'inactive user' => [['is_active' => false]],
    'ERP access disabled' => [['can_access_erp' => false]],
]);

it('logs in an active ERP user, regenerates the session, and creates no remember login', function (): void {
    Carbon::setTestNow('2026-08-16 12:00:00');
    $userA = phoneTokenUser(['phone' => '09120000001']);
    $userB = phoneTokenUser(['phone' => '09120000002']);
    $this->actingAs($userA);
    $oldSessionId = session()->getId();
    $recallerName = auth('web')->getRecallerName();

    $response = $this->get(route('auth.phone.verify', [
        'phone' => $userB->phone,
        'token' => validPhoneToken($userB->phone),
    ]));

    $response->assertRedirect(route('access.unassigned'))
        ->assertCookieMissing($recallerName);
    $this->assertAuthenticatedAs($userB);
    expect(session()->getId())->not->toBe($oldSessionId)
        ->and($userB->fresh()->remember_token)->toBeNull();
});

it('uses FirstAllowedPageResolver after a successful login', function (): void {
    Carbon::setTestNow('2026-08-16 12:00:00');
    $user = phoneTokenUser();
    $destination = 'https://inventory.test/allowed-page';

    $this->mock(FirstAllowedPageResolver::class)
        ->shouldReceive('destination')
        ->once()
        ->withArgs(fn (User $resolvedUser, $request) => $resolvedUser->is($user) && $request !== null)
        ->andReturn($destination);

    $this->get(route('auth.phone.verify', [
        'phone' => $user->phone,
        'token' => validPhoneToken($user->phone),
    ]))->assertRedirect($destination);

    $this->assertAuthenticatedAs($user);
});

it('allows CRM-managed users through a valid phone handoff', function (): void {
    Carbon::setTestNow('2026-08-16 12:00:00');
    $user = phoneTokenUser([
        'crm_user_id' => '18',
        'is_crm_managed' => true,
    ]);

    $this->get(route('auth.phone.verify', [
        'phone' => $user->phone,
        'token' => validPhoneToken($user->phone),
    ]))->assertRedirect(route('access.unassigned'));

    $this->assertAuthenticatedAs($user);
    expect(ActivityLog::query()->where('action', 'local_emergency_login')->exists())->toBeFalse();
});

it('allows an eligible local user and records the local handoff audit', function (): void {
    Carbon::setTestNow('2026-08-16 12:00:00');
    $user = phoneTokenUser([
        'crm_user_id' => null,
        'is_crm_managed' => false,
    ]);

    $this->get(route('auth.phone.verify', [
        'phone' => $user->phone,
        'token' => validPhoneToken($user->phone),
    ]))->assertRedirect(route('access.unassigned'));

    $this->assertAuthenticatedAs($user);
    expect(ActivityLog::query()->where('action', 'local_emergency_login')->exists())->toBeTrue();
});

it('never leaks an invalid handoff token into responses or activity logs', function (): void {
    $phone = '09120000018';
    $token = '0123456789abcdef0123456789abcdef';
    $logHandler = new TestHandler();
    Log::swap(new Logger('phone-token-test', [$logHandler]));

    $response = $this->get(route('auth.phone.verify', compact('phone', 'token')));

    $response->assertForbidden();
    expect($response->getContent())->not->toContain($token)
        ->and(ActivityLog::query()->get()->toJson())->not->toContain($token)
        ->and(json_encode($logHandler->getRecords(), JSON_THROW_ON_ERROR))->not->toContain($token);
});

it('verifies tokens deterministically only for the same phone inside their lifetime', function (): void {
    Carbon::setTestNow('2026-08-16 12:00:00');
    $service = app(TokenService::class);
    $phone = '09120000018';
    $token = $service->hash($phone)['token'];

    Carbon::setTestNow('2026-08-16 12:09:00');
    expect($service->verify($phone, $token))->toBeTrue()
        ->and($service->verify('09120000019', $token))->toBeFalse()
        ->and($service->verify($phone, str_repeat('f', 32)))->toBeFalse();

    Carbon::setTestNow('2026-08-16 12:10:00');
    expect($service->verify($phone, $token))->toBeFalse();
});
