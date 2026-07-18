<?php

use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Route;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Route as RouteFacade;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/*
|--------------------------------------------------------------------------
| Database isolation
|--------------------------------------------------------------------------
|
| قبل از اجرای تست‌ها، تمام migrationها روی دیتابیس تست اجرا می‌شوند.
| دیتابیس تست پروژه طبق phpunit.xml از نوع SQLite و داخل حافظه است.
|
*/
uses(RefreshDatabase::class);

/*
|--------------------------------------------------------------------------
| Prevent real external side effects
|--------------------------------------------------------------------------
|
| ایمیل، صف، نوتیفیکیشن، فایل و درخواست‌های خارجی شبیه‌سازی می‌شوند.
| دیتابیس همچنان واقعاً در محیط testing اجرا خواهد شد.
|
*/
beforeEach(function (): void {
    Http::fake();
    Queue::fake();
    Mail::fake();
    Notification::fake();
    Storage::fake('local');

    $this->withoutMiddleware(ValidateCsrfToken::class);

    app(PermissionRegistrar::class)->forgetCachedPermissions();
});

/*
|--------------------------------------------------------------------------
| Route helpers
|--------------------------------------------------------------------------
*/

$routeCoverageRoutes = static function (): Collection {
    return collect(RouteFacade::getRoutes())
        ->filter(function (Route $route): bool {
            $uri = ltrim($route->uri(), '/');

            /*
             * مسیرهای مربوط به ابزارهای توسعه و خطایابی،
             * جزو منطق اصلی نرم‌افزار نیستند.
             */
            return ! Str::startsWith($uri, [
                '_ignition',
                'telescope',
                'horizon',
            ]);
        })
        ->values();
};

$routeCoverageMethods = static function (Route $route): array {
    /*
     * HEAD همراه GET به‌صورت خودکار ثبت می‌شود؛
     * برای جلوگیری از تکرار، HEAD و OPTIONS حذف شده‌اند.
     */
    return array_values(
        array_unique(
            array_diff(
                $route->methods(),
                ['HEAD', 'OPTIONS']
            )
        )
    );
};

$routeCoverageLabel = static function (
    Route $route,
    string $method
): string {
    $name = $route->getName() ?? 'unnamed';

    return "{$method} /{$route->uri()} [{$name}]";
};

$routeCoverageMiddleware = static function (Route $route): array {
    return collect($route->gatherMiddleware())
        ->map(function (mixed $middleware): string {
            return is_string($middleware)
                ? $middleware
                : get_debug_type($middleware);
        })
        ->values()
        ->all();
};

$routeCoverageNeedsAuthentication = static function (
    Route $route
) use ($routeCoverageMiddleware): bool {
    return collect($routeCoverageMiddleware($route))
        ->contains(function (string $middleware): bool {
            return $middleware === 'auth'
                || str_starts_with($middleware, 'auth:')
                || str_contains(
                    $middleware,
                    'Authenticate'
                );
        });
};

$routeCoverageNeedsAuthorization = static function (
    Route $route
) use ($routeCoverageMiddleware): bool {
    $middlewareList = collect(
        $routeCoverageMiddleware($route)
    );

    $hasExplicitAuthorizationMiddleware = $middlewareList
        ->contains(function (string $middleware): bool {
            return str_starts_with($middleware, 'permission:')
                || str_starts_with($middleware, 'role:')
                || str_starts_with(
                    $middleware,
                    'role_or_permission:'
                )
                || str_contains(
                    $middleware,
                    'CheckPermission'
                )
                || str_contains(
                    $middleware,
                    'CheckRoleOrRoutePermission'
                )
                || str_contains(
                    $middleware,
                    'RoleOrPermissionMiddleware'
                );
        });

    $routeName = $route->getName();

    $hasMappedRoutePermission = $routeName !== null
        && array_key_exists(
            $routeName,
            PermissionCatalog::routePermissions()
        );

    return $hasExplicitAuthorizationMiddleware
        || $hasMappedRoutePermission;
};

$routeCoverageUri = static function (Route $route): string {
    $uri = $route->uri();

    foreach ($route->parameterNames() as $parameter) {
        $bindingField = $route->bindingFieldFor($parameter);
        $where = $route->wheres[$parameter] ?? null;

        $value = match (true) {
            $bindingField === 'uuid' =>
                '00000000-0000-4000-8000-000000000001',

            str_contains(strtolower($parameter), 'uuid') =>
                '00000000-0000-4000-8000-000000000001',

            $bindingField === 'slug' =>
                'test',

            $bindingField === 'code' =>
                'TEST',

            str_contains(strtolower($parameter), 'slug') =>
                'test',

            str_contains(strtolower($parameter), 'date') =>
                '2026-01-01',

            $where !== null
                && (
                    str_contains((string) $where, '0-9')
                    || str_contains((string) $where, '\d')
                ) =>
                1,

            $where !== null
                && (
                    str_contains((string) $where, 'A-Z')
                    || str_contains((string) $where, 'a-z')
                ) =>
                'test',

            default =>
                1,
        };

        $pattern = '/\{'
            .preg_quote($parameter, '/')
            .'(?:\:[^}]+)?'
            .'\??\}/';

        $uri = preg_replace(
            $pattern,
            rawurlencode((string) $value),
            $uri
        );
    }

    if ($uri === '/') {
        return '/';
    }

    return '/'.ltrim($uri, '/');
};

$routeCoverageRequest = static function (
    object $testCase,
    string $method,
    string $uri,
    array $payload = []
): \Illuminate\Testing\TestResponse {
    /*
     * Accept: application/json باعث می‌شود خطاهای validation
     * معمولاً با کد 422 برگردند و قابل بررسی باشند.
     */
    return $testCase->call(
        $method,
        $uri,
        $payload,
        [],
        [],
        [
            'HTTP_ACCEPT' => 'application/json',
        ]
    );
};

$routeCoverageDatabaseSnapshot = static function (): array {
    $snapshot = [];

    foreach (Schema::getTableListing() as $table) {
        $tableName = (string) $table;
        $normalizedName = strtolower($tableName);

        /*
         * جدول‌های فنی و گزارش رویداد از مقایسه تغییرات
         * داده‌های اصلی کنار گذاشته می‌شوند.
         */
        if (
            Str::contains($normalizedName, [
                'migration',
                'sqlite_sequence',
                'activity_log',
                'notification',
                'cache',
                'session',
                'job',
                'telescope',
                'pulse',
            ])
        ) {
            continue;
        }

        try {
            $snapshot[$tableName] = DB::table(
                $tableName
            )->count();
        } catch (\Throwable) {
            /*
             * اگر موردی view یا جدول غیرقابل شمارش باشد،
             * از snapshot کنار گذاشته می‌شود.
             */
            continue;
        }
    }

    ksort($snapshot);

    return $snapshot;
};

$routeCoverageHasValidationErrors = static function (
    \Illuminate\Testing\TestResponse $response
): bool {
    if ($response->getStatusCode() === 422) {
        return true;
    }

    $errors = session('errors');

    return is_object($errors)
        && method_exists($errors, 'any')
        && $errors->any();
};

/*
|--------------------------------------------------------------------------
| Testing database safety
|--------------------------------------------------------------------------
*/

it('uses a completely isolated in-memory testing database', function (): void {
    expect(app()->environment('testing'))->toBeTrue(
        'APP_ENV must be testing before route tests can run.'
    );

    expect(DB::connection()->getDriverName())->toBe(
        'sqlite',
        'Route tests must use SQLite, not the main MySQL database.'
    );

    expect(
        config('database.connections.sqlite.database')
    )->toBe(
        ':memory:',
        'The testing database must be SQLite :memory:.'
    );

    expect(DB::connection()->getPdo())->toBeInstanceOf(
        \PDO::class,
        'Laravel could not connect to the testing database.'
    );
});

/*
|--------------------------------------------------------------------------
| Route and controller structure
|--------------------------------------------------------------------------
*/

it('maps every registered route to an existing callable controller method', function () use (
    $routeCoverageRoutes,
    $routeCoverageMethods,
    $routeCoverageLabel
): void {
    $failures = [];

    foreach ($routeCoverageRoutes() as $route) {
        foreach ($routeCoverageMethods($route) as $method) {
            $label = $routeCoverageLabel($route, $method);
            $action = $route->getActionName();

            try {
                expect($route->uri())->toBeString();

                expect($route->methods())->toBeArray()->not->toBeEmpty(
                    "{$label}: no HTTP method was registered."
                );

                if ($action === 'Closure') {
                    expect(
                        $route->getAction('uses')
                    )->toBeInstanceOf(
                        \Closure::class,
                        "{$label}: invalid Closure action."
                    );

                    continue;
                }

                if (str_contains($action, '@')) {
                    [$controllerClass, $controllerMethod] = explode(
                        '@',
                        $action,
                        2
                    );
                } else {
                    $controllerClass = $action;
                    $controllerMethod = '__invoke';
                }

                expect(class_exists($controllerClass))->toBeTrue(
                    "{$label}: controller {$controllerClass} does not exist."
                );

                if (! class_exists($controllerClass)) {
                    continue;
                }

                expect(
                    method_exists(
                        $controllerClass,
                        $controllerMethod
                    )
                )->toBeTrue(
                    "{$label}: method {$controllerClass}::{$controllerMethod} does not exist."
                );

                if (
                    ! method_exists(
                        $controllerClass,
                        $controllerMethod
                    )
                ) {
                    continue;
                }

                $reflection = new \ReflectionMethod(
                    $controllerClass,
                    $controllerMethod
                );

                expect($reflection->isPublic())->toBeTrue(
                    "{$label}: controller method must be public."
                );
            } catch (\Throwable $exception) {
                $failures[] = "{$label} => "
                    .$exception->getMessage();
            }
        }
    }

    $this->assertSame(
        [],
        $failures,
        "Controller and route structure failures:\n"
        .implode("\n", $failures)
    );
});

/*
|--------------------------------------------------------------------------
| Guest authentication middleware
|--------------------------------------------------------------------------
*/

it('blocks guests from every route protected by authentication middleware', function () use (
    $routeCoverageRoutes,
    $routeCoverageMethods,
    $routeCoverageNeedsAuthentication,
    $routeCoverageUri,
    $routeCoverageRequest,
    $routeCoverageLabel
): void {
    $failures = [];

    foreach ($routeCoverageRoutes() as $route) {
        if (! $routeCoverageNeedsAuthentication($route)) {
            continue;
        }

        foreach ($routeCoverageMethods($route) as $method) {
            $label = $routeCoverageLabel($route, $method);

            DB::beginTransaction();

            try {
                Auth::logout();
                Auth::forgetGuards();
                session()->flush();

                $response = $routeCoverageRequest(
                    $this,
                    $method,
                    $routeCoverageUri($route)
                );

                $status = $response->getStatusCode();

                /*
                 * 401: درخواست JSON بدون ورود
                 * 302: هدایت به صفحه login
                 */
                expect($status)->toBeIn(
                    [302, 401],
                    "{$label}: guest user was not blocked. HTTP {$status} returned."
                );

                if ($status === 302) {
                    expect(
                        $response->headers->get('Location')
                    )->not->toBeNull(
                        "{$label}: authentication redirect has no Location header."
                    );
                }
            } catch (\Throwable $exception) {
                $failures[] = "{$label} => "
                    .$exception->getMessage();
            } finally {
                DB::rollBack();
            }
        }
    }

    $this->assertSame(
        [],
        $failures,
        "Guest authentication failures:\n"
        .implode("\n", $failures)
    );
});

/*
|--------------------------------------------------------------------------
| Role and permission middleware
|--------------------------------------------------------------------------
*/

it('returns 403 for authenticated users without required roles and permissions', function () use (
    $routeCoverageRoutes,
    $routeCoverageMethods,
    $routeCoverageNeedsAuthorization,
    $routeCoverageUri,
    $routeCoverageRequest,
    $routeCoverageLabel
): void {
    $user = User::factory()->create([
        'name' => 'Route Test User',
        'email' => 'route-user@example.test',
        'phone' => '09120000001',
        'username' => 'route_test_user',
        'is_active' => true,
    ]);

    $failures = [];

    foreach ($routeCoverageRoutes() as $route) {
        if (! $routeCoverageNeedsAuthorization($route)) {
            continue;
        }

        foreach ($routeCoverageMethods($route) as $method) {
            $label = $routeCoverageLabel($route, $method);

            DB::beginTransaction();

            try {
                session()->flush();

                $this->actingAs($user->fresh());

                $response = $routeCoverageRequest(
                    $this,
                    $method,
                    $routeCoverageUri($route)
                );

                $status = $response->getStatusCode();

                if ($route->parameterNames() === []) {
                    expect($status)->toBe(
                        403,
                        "{$label}: user without permission must receive HTTP 403, but HTTP {$status} returned."
                    );
                } else {
                    /*
                     * در روت پارامتردار ممکن است Model Binding
                     * قبل از middleware به 404 برسد.
                     */
                    expect($status)->toBeIn(
                        [403, 404],
                        "{$label}: unauthorized user must receive HTTP 403 or binding 404, but HTTP {$status} returned."
                    );
                }
            } catch (\Throwable $exception) {
                $failures[] = "{$label} => "
                    .$exception->getMessage();
            } finally {
                DB::rollBack();
            }
        }
    }

    $this->assertSame(
        [],
        $failures,
        "Role and permission failures:\n"
        .implode("\n", $failures)
    );
});

/*
|--------------------------------------------------------------------------
| Real route execution as super administrator
|--------------------------------------------------------------------------
*/

it('executes every GET POST PUT PATCH and DELETE route without server errors', function () use (
    $routeCoverageRoutes,
    $routeCoverageMethods,
    $routeCoverageNeedsAuthentication,
    $routeCoverageNeedsAuthorization,
    $routeCoverageUri,
    $routeCoverageRequest,
    $routeCoverageLabel,
    $routeCoverageDatabaseSnapshot,
    $routeCoverageHasValidationErrors
): void {
    /*
     * محافظت نهایی: این تست تحت هیچ شرایطی نباید
     * روی دیتابیس اصلی اجرا شود.
     */
    expect(app()->environment('testing'))->toBeTrue();
    expect(DB::connection()->getDriverName())->toBe('sqlite');

    expect(
        config('database.connections.sqlite.database')
    )->toBe(':memory:');

    $superAdminRole = Role::findOrCreate(
        'super_admin',
        'web'
    );

    $superAdmin = User::factory()->create([
        'name' => 'Route Super Admin',
        'email' => 'route-admin@example.test',
        'phone' => '09120000002',
        'username' => 'route_super_admin',
        'is_active' => true,
    ]);

    $superAdmin->assignRole($superAdminRole);

    app(PermissionRegistrar::class)->forgetCachedPermissions();

    $superAdminId = $superAdmin->getKey();
    $failures = [];

    foreach ($routeCoverageRoutes() as $route) {
        foreach ($routeCoverageMethods($route) as $method) {
            $label = $routeCoverageLabel($route, $method);
            $uri = $routeCoverageUri($route);

            $isProtected = $routeCoverageNeedsAuthentication($route)
                || $routeCoverageNeedsAuthorization($route);

            $isWriteRequest = in_array(
                $method,
                ['POST', 'PUT', 'PATCH', 'DELETE'],
                true
            );

            DB::beginTransaction();

            try {
                Auth::logout();
                Auth::forgetGuards();
                session()->flush();

                if ($isProtected) {
                    $freshAdmin = User::query()->find(
                        $superAdminId
                    );

                    expect($freshAdmin)->not->toBeNull(
                        "{$label}: super administrator test user is missing."
                    );

                    if ($freshAdmin !== null) {
                        $this->actingAs($freshAdmin);
                    }
                }

                $beforeDatabase = $isWriteRequest
                    ? $routeCoverageDatabaseSnapshot()
                    : [];

                /*
                 * درخواست واقعی از داخل Kernel برنامه عبور می‌کند.
                 * برای درخواست‌های نوشتنی، payload خالی ارسال می‌شود
                 * تا validation نیز بررسی شود.
                 */
                $response = $routeCoverageRequest(
                    $this,
                    $method,
                    $uri,
                    []
                );

                $status = $response->getStatusCode();

                expect($status)->toBeLessThan(
                    500,
                    "{$label}: application returned server error HTTP {$status}."
                );

                if (
                    $isProtected
                    && in_array($status, [401, 403], true)
                ) {
                    throw new \RuntimeException(
                        "{$label}: super administrator was denied with HTTP {$status}."
                    );
                }

                if (
                    $status === 404
                    && $route->parameterNames() === []
                ) {
                    throw new \RuntimeException(
                        "{$label}: route has no parameters but returned HTTP 404."
                    );
                }

                /*
                 * پاسخ موفق باید محتوا داشته باشد،
                 * مگر پاسخ‌های 204 که عمداً بدون محتوا هستند.
                 */
                if (
                    $status >= 200
                    && $status < 300
                    && $status !== 204
                ) {
                    expect(
                        trim((string) $response->getContent())
                    )->not->toBeEmpty(
                        "{$label}: successful response has empty content."
                    );

                    $contentType = (string) $response
                        ->headers
                        ->get('Content-Type');

                    if (
                        str_contains(
                            strtolower($contentType),
                            'application/json'
                        )
                    ) {
                        json_decode(
                            (string) $response->getContent(),
                            true
                        );

                        expect(json_last_error())->toBe(
                            JSON_ERROR_NONE,
                            "{$label}: response claims to be JSON but contains invalid JSON."
                        );
                    }
                }

                /*
                 * هر redirect باید مقصد مشخص داشته باشد.
                 */
                if ($status >= 300 && $status < 400) {
                    expect(
                        $response->headers->get('Location')
                    )->not->toBeNull(
                        "{$label}: redirect response has no Location header."
                    );
                }

                /*
                 * اگر payload خالی باعث validation error شد،
                 * نباید اطلاعات اصلی دیتابیس تغییر کرده باشد.
                 */
                if (
                    $isWriteRequest
                    && $routeCoverageHasValidationErrors(
                        $response
                    )
                ) {
                    $afterDatabase = $routeCoverageDatabaseSnapshot();

                    expect($afterDatabase)->toBe(
                        $beforeDatabase,
                        "{$label}: database changed despite validation failure."
                    );
                }
            } catch (\Throwable $exception) {
                $message = $exception->getMessage();

                if (isset($response)) {
                    $responseBody = trim(
                        strip_tags(
                            (string) $response->getContent()
                        )
                    );

                    if ($responseBody !== '') {
                        $message .= ' | Response: '
                            .Str::limit(
                                preg_replace(
                                    '/\s+/',
                                    ' ',
                                    $responseBody
                                ),
                                300
                            );
                    }
                }

                $failures[] = "{$label} => {$message}";
            } finally {
                /*
                 * هر تغییر موفق یا ناموفق این روت به عقب برمی‌گردد.
                 * در نتیجه هیچ Route تستی داده دائمی ایجاد نمی‌کند.
                 */
                DB::rollBack();

                unset($response);
            }
        }
    }

    $this->assertSame(
        [],
        $failures,
        "Functional route failures:\n"
        .implode("\n", $failures)
    );
});