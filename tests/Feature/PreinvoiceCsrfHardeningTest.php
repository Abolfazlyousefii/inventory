<?php

use App\Models\User;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Http\Request;

beforeEach(function () {
    config([
        'app.key' => 'base64:'.base64_encode(str_repeat('a', 32)),
        'session.driver' => 'array',
    ]);
});

it('keeps the preinvoice form and browser endpoints same-origin', function () {
    $view = file_get_contents(resource_path('views/preinvoice/create.blade.php'));

    expect($view)
        ->toContain('@csrf')
        ->toContain('input[name="_token"]')
        ->toContain("credentials: 'same-origin'")
        ->toContain('refreshCsrfToken()')
        ->toContain("route('preinvoice.draft.save', absolute: false)")
        ->toContain("route('preinvoice.autosave', absolute: false)")
        ->toContain("route('preinvoice.api.reservations.sync', absolute: false)")
        ->not->toContain("url('/preinvoice")
        ->not->toMatch('/https?:\\/\\/(?:localhost|127\\.0\\.0\\.1|10\\.|192\\.168\\.)/');
});

it('exposes only the current csrf token to an authenticated user', function () {
    $user = new User;
    $user->id = 123;

    $response = $this->actingAs($user)->getJson(route('session.csrf-token'));

    $response->assertOk()
        ->assertExactJson([
            'ok' => true,
            'csrf_token' => csrf_token(),
        ]);
});

it('does not expose the csrf refresh endpoint anonymously', function () {
    $this->getJson(route('session.csrf-token'))->assertUnauthorized();
});

it('accepts a matching token and rejects a stale token before controller code runs', function () {
    $request = Request::create('/preinvoice/draft', 'POST', ['_token' => 'current-token']);
    $session = app('session')->driver();
    $session->start();
    $session->put('_token', 'current-token');
    $request->setLaravelSession($session);
    $middleware = app(ValidateCsrfToken::class);
    $reachedController = false;
    $originalEnvironment = $this->app['env'];
    $this->app['env'] = 'production';

    try {
        $validResponse = $middleware->handle($request, function () use (&$reachedController) {
            $reachedController = true;
            return response('ok');
        });

        expect($validResponse->getStatusCode())->toBe(200)
            ->and($reachedController)->toBeTrue();

        $staleRequest = Request::create('/preinvoice/draft', 'POST', ['_token' => 'stale-token']);
        $staleRequest->setLaravelSession($session);
        $reachedController = false;

        expect(fn () => $middleware->handle($staleRequest, function () use (&$reachedController) {
            $reachedController = true;
            return response('should not run');
        }))->toThrow(TokenMismatchException::class)
            ->and($reachedController)->toBeFalse();
    } finally {
        $this->app['env'] = $originalEnvironment;
    }
});

it('uses an inventory-specific cookie and production-safe cookie knobs', function () {
    $example = file_get_contents(base_path('.env.example'));
    $sessionConfig = file_get_contents(config_path('session.php'));

    expect($example)
        ->toContain('SESSION_COOKIE=inventory_session')
        ->toContain('SESSION_SECURE_COOKIE=false')
        ->toContain('SESSION_HTTP_ONLY=true')
        ->toContain('SESSION_SAME_SITE=lax')
        ->and($sessionConfig)
        ->toContain("'SESSION_COOKIE',")
        ->toContain("env('SESSION_DOMAIN')")
        ->toContain("env('SESSION_SECURE_COOKIE')")
        ->toContain("env('SESSION_LIFETIME', 120)");
});

it('distinguishes csrf mismatch from authentication loss and retries only idempotent reservation writes', function () {
    $view = file_get_contents(resource_path('views/preinvoice/create.blade.php'));

    expect($view)
        ->toContain('class CsrfMismatchError extends Error')
        ->toContain('response.status === 419')
        ->toContain('postReservation(url, body, false)')
        ->toContain("'X-Requested-With': 'XMLHttpRequest'")
        ->toContain('submission_token: token')
        ->toContain('if (!(e instanceof CsrfMismatchError)) groupedSelections = previousSelections;');
});

it('keeps every preinvoice write route behind csrf middleware', function () {
    $names = [
        'preinvoice.draft.save',
        'preinvoice.draft.update',
        'preinvoice.autosave',
        'preinvoice.api.reservations.sync',
        'preinvoice.api.reservations.release',
        'preinvoice.reservations.heartbeat',
        'preinvoice.reservations.release-token',
    ];

    foreach ($names as $name) {
        expect(app('router')->getRoutes()->getByName($name)->gatherMiddleware())
            ->toContain('web');
    }

    expect(file_get_contents(base_path('bootstrap/app.php')))
        ->not->toContain('validateCsrfTokens(except:')
        ->not->toContain('VerifyCsrfToken::$except');
});
