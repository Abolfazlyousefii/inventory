<?php

use App\Http\Middleware\RetireCommercialCommissionAutomation;
use App\Models\CommissionDocument;
use App\Models\CommissionPeriod;
use App\Models\CommissionRateRevision;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Symfony\Component\HttpKernel\Exception\HttpException;

uses(RefreshDatabase::class);

function retiredCommissionUser(array $permissionKeys, string $roleName): User
{
    $role = Role::findOrCreate($roleName, 'web');
    $role->syncPermissions(Permission::query()->whereIn('key', $permissionKeys)->get());

    return tap(User::factory()->create(), fn (User $user) => $user->assignRole($role));
}

function retiredCommissionFinanceUser(): User
{
    return retiredCommissionUser(['page.finance.seller_sales_documents'], 'RetiredCommissionFinance');
}

function retiredCommissionAutomationUser(): User
{
    return retiredCommissionUser(['page.commercial.commissions'], 'RetiredCommissionAutomation');
}

function retiredCommissionHistoricalRecords(User $user): array
{
    $period = CommissionPeriod::query()->create([
        'label' => 'Retirement test period', 'start_at' => '2026-09-01', 'end_at' => '2026-10-01',
        'cycle_day_snapshot' => 1, 'status' => CommissionPeriod::STATUS_OPEN,
    ]);
    $document = CommissionDocument::query()->create([
        'document_number' => 'RETIREMENT-DOCUMENT-'.$user->id, 'seller_id' => $user->id,
        'commission_period_id' => $period->id, 'status' => CommissionDocument::STATUS_DRAFT, 'created_by' => $user->id,
    ]);

    return [$period, $document];
}

function assertRetiredCommissionHistoryIsUntouched(CommissionPeriod $period, CommissionDocument $document): void
{
    expect(CommissionPeriod::query()->count())->toBe(1)
        ->and(CommissionDocument::query()->count())->toBe(1)
        ->and(CommissionRateRevision::query()->count())->toBe(0)
        ->and(CommissionPeriod::query()->findOrFail($period->id)->needs_recalculation)->toBeFalse()
        ->and(CommissionDocument::query()->findOrFail($document->id)->status)->toBe(CommissionDocument::STATUS_DRAFT);
}

it('keeps the financial seller commission entrypoint accessible', function () {
    $this->actingAs(retiredCommissionFinanceUser())->get(route('finance.seller-sales.index'))->assertOk();
});

it('redirects the real legacy GET and HEAD routes without touching historical data', function () {
    $user = retiredCommissionAutomationUser();
    [$period, $document] = retiredCommissionHistoricalRecords($user);

    $this->actingAs($user)->get(route('commercial.commissions.index'))
        ->assertRedirect(route('finance.seller-sales.index'))->assertSessionHas('info');
    assertRetiredCommissionHistoryIsUntouched($period, $document);

    $this->actingAs($user)->call('HEAD', route('commercial.commissions.index'))
        ->assertRedirect(route('finance.seller-sales.index'));
    assertRetiredCommissionHistoryIsUntouched($period, $document);
});

it('returns gone for real legacy POST PUT and DELETE routes before they mutate historical data', function () {
    $user = retiredCommissionAutomationUser();
    [$period, $document] = retiredCommissionHistoricalRecords($user);

    $this->actingAs($user)->post(route('commercial.commissions.periods.recalculate', $period))->assertGone();
    assertRetiredCommissionHistoryIsUntouched($period, $document);

    $this->actingAs($user)->put(route('commercial.commissions.settings.update'), ['cycle_day' => 5])->assertGone();
    assertRetiredCommissionHistoryIsUntouched($period, $document);

    $this->actingAs($user)->delete(route('commercial.commissions.rates.destroy'))->assertGone();
    assertRetiredCommissionHistoryIsUntouched($period, $document);
});

it('returns the retirement message as JSON for a real legacy mutation route', function () {
    $user = retiredCommissionAutomationUser();
    [$period, $document] = retiredCommissionHistoricalRecords($user);

    $this->actingAs($user)->postJson(route('commercial.commissions.periods.recalculate', $period))
        ->assertGone()->assertHeader('Content-Type', 'application/json')->assertJsonStructure(['message'])
        ->assertJsonPath('message', 'فرآیند مستقل پورسانت بازنشسته شده است. برای مدیریت اسناد پورسانت از گزارش‌های مالی استفاده کنید.');
    assertRetiredCommissionHistoryIsUntouched($period, $document);
});

it('blocks PATCH in the middleware without calling the next closure', function () {
    $nextWasCalled = false;
    try {
        app(RetireCommercialCommissionAutomation::class)->handle(
            Request::create('/commercial/commissions/settings', 'PATCH'),
            function () use (&$nextWasCalled) { $nextWasCalled = true; },
        );
    } catch (HttpException $exception) {
        expect($exception->getStatusCode())->toBe(410);
    }

    expect($nextWasCalled)->toBeFalse();
});

it('keeps every financial seller-sales route registered and outside the retirement middleware', function () {
    $names = [
        'finance.seller-sales.index',
        'finance.seller-sales.create',
        'finance.seller-sales.available-invoices',
        'finance.seller-sales.store',
        'finance.seller-sales.show',
        'finance.seller-sales.edit',
        'finance.seller-sales.update',
        'finance.seller-sales.destroy',
        'finance.seller-sales.print',
        'finance.seller-sales.adjustments.store',
        'finance.seller-sales.adjustments.destroy',
        'finance.seller-sales.bonus',
        'finance.seller-sales.confirm',
        'finance.seller-sales.finalize',
        'finance.seller-sales.recalculate',
    ];

    foreach ($names as $name) {
        $route = Route::getRoutes()->getByName($name);
        expect($route)->not->toBeNull()
            ->and($route->gatherMiddleware())->not->toContain('retired.commission.automation')
            ->and($route->gatherMiddleware())->not->toContain(RetireCommercialCommissionAutomation::class);
    }
});

it('applies the retirement guard to every and only legacy automation route', function () {
    $routes = collect(Route::getRoutes()->getRoutes());
    $legacyRoutes = $routes->filter(fn ($route) => str_starts_with((string) $route->getName(), 'commercial.commissions.'));

    expect($legacyRoutes)->not->toBeEmpty();
    foreach ($legacyRoutes as $route) {
        expect($route->gatherMiddleware())->toContain('retired.commission.automation');
    }

    expect($routes->filter(fn ($route) => in_array('retired.commission.automation', $route->gatherMiddleware(), true))
        ->every(fn ($route) => str_starts_with((string) $route->getName(), 'commercial.commissions.')))->toBeTrue();
});

it('does not render an automation commission entry in the sidebar or dashboard', function () {
    $user = retiredCommissionUser(['page.commercial.commissions', 'dashboard.view'], 'RetiredCommissionDashboard');

    $this->actingAs($user)->view('layouts.sidebar')->assertDontSee(route('commercial.commissions.index'), false);
    $this->actingAs($user)->get(route('dashboard'))->assertOk()
        ->assertDontSee('seller-commission-widget', false)->assertDontSee('dashboard-commission-title', false);
});
