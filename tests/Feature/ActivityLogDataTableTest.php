<?php

use App\Models\ActivityLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

function activityLogViewer(): User
{
    $user = User::factory()->create([
        'is_active' => true,
        'can_access_erp' => true,
    ]);

    $user->assignRole(Role::findOrCreate('Owner', 'web'));

    return $user;
}

function makeActivityLog(array $overrides = []): ActivityLog
{
    return ActivityLog::create(array_replace([
        'user_id' => null,
        'action' => 'created',
        'subject_type' => 'App\\Models\\Invoice',
        'subject_id' => 55,
        'description' => 'یک فاکتور ساخته شد',
        'occurred_at' => now(),
    ], $overrides));
}

it('renders the activity log page as HTML and never as raw DataTables JSON', function (): void {
    makeActivityLog();

    $response = $this->actingAs(activityLogViewer())->get(route('activity-logs.index'));

    $response->assertOk();
    expect($response->headers->get('content-type'))->toContain('text/html');

    $response->assertSee('لاگ فعالیت‌ها');
    $response->assertSee('activity-logs-table', escape: false);
    $response->assertDontSee('"recordsTotal"', escape: false);
});

it('passes the action filter options the blade view requires', function (): void {
    makeActivityLog(['action' => 'created']);
    makeActivityLog(['action' => 'updated']);

    $response = $this->actingAs(activityLogViewer())->get(route('activity-logs.index'));

    $response->assertOk();
    $response->assertViewHas('actions', function (array $actions): bool {
        return in_array('created', $actions, true) && in_array('updated', $actions, true);
    });
});

it('returns a server-side DataTables payload for the AJAX request', function (): void {
    makeActivityLog();

    $response = $this->actingAs(activityLogViewer())
        ->getJson(route('activity-logs.index', ['draw' => 1, 'start' => 0, 'length' => 10]));

    $response->assertOk()
        ->assertJsonStructure(['draw', 'recordsTotal', 'recordsFiltered', 'data']);

    expect($response->json('recordsTotal'))->toBe(1)
        ->and($response->json('recordsFiltered'))->toBe(1);
});

it('paginates server side using start and length', function (): void {
    for ($i = 0; $i < 7; $i++) {
        makeActivityLog(['description' => 'ردیف ' . $i]);
    }

    $response = $this->actingAs(activityLogViewer())
        ->getJson(route('activity-logs.index', ['draw' => 1, 'start' => 0, 'length' => 3]));

    $response->assertOk();

    expect($response->json('recordsTotal'))->toBe(7)
        ->and($response->json('data'))->toHaveCount(3);
});

it('orders server side by the requested column', function (): void {
    $first = makeActivityLog(['description' => 'اول']);
    $last = makeActivityLog(['description' => 'آخر']);

    $params = [
        'draw' => 1,
        'start' => 0,
        'length' => 10,
        'columns' => [
            0 => ['data' => 'id', 'name' => 'id', 'searchable' => 'true', 'orderable' => 'true', 'search' => ['value' => '', 'regex' => 'false']],
        ],
        'order' => [0 => ['column' => 0, 'dir' => 'desc']],
    ];

    $response = $this->actingAs(activityLogViewer())
        ->getJson(route('activity-logs.index', $params));

    $response->assertOk();

    expect($response->json('data.0.id'))->toBe($last->id)
        ->and($response->json('data.1.id'))->toBe($first->id);
});

it('applies the q filter server side', function (): void {
    makeActivityLog(['description' => 'فاکتور فروش ثبت شد']);
    makeActivityLog(['description' => 'کالای انبار اصلاح شد']);

    $response = $this->actingAs(activityLogViewer())
        ->getJson(route('activity-logs.index', ['draw' => 1, 'start' => 0, 'length' => 10, 'q' => 'فاکتور فروش']));

    $response->assertOk();

    expect($response->json('recordsFiltered'))->toBe(1)
        ->and($response->json('data.0.description'))->toBe('فاکتور فروش ثبت شد');
});

it('applies the action filter server side', function (): void {
    makeActivityLog(['action' => 'created']);
    makeActivityLog(['action' => 'deleted']);
    makeActivityLog(['action' => 'deleted']);

    $response = $this->actingAs(activityLogViewer())
        ->getJson(route('activity-logs.index', ['draw' => 1, 'start' => 0, 'length' => 10, 'action' => 'deleted']));

    $response->assertOk();

    expect($response->json('recordsFiltered'))->toBe(2);

    foreach ($response->json('data') as $row) {
        expect($row['action_badge'])->toContain('deleted');
    }
});

it('shows the user name when present and سیستم when the log has no user', function (): void {
    $actor = User::factory()->create(['name' => 'کاربر تست']);

    makeActivityLog(['user_id' => $actor->id, 'action' => 'with_user']);
    makeActivityLog(['user_id' => null, 'action' => 'without_user']);

    $viewer = activityLogViewer();

    $withUser = $this->actingAs($viewer)
        ->getJson(route('activity-logs.index', ['draw' => 1, 'start' => 0, 'length' => 10, 'action' => 'with_user']));

    $withoutUser = $this->actingAs($viewer)
        ->getJson(route('activity-logs.index', ['draw' => 1, 'start' => 0, 'length' => 10, 'action' => 'without_user']));

    expect($withUser->json('data.0.user'))->toBe('کاربر تست')
        ->and($withoutUser->json('data.0.user'))->toBe('سیستم');
});

it('formats occurred_at as a Jalali date and renders the subject reference', function (): void {
    makeActivityLog([
        'occurred_at' => '2026-08-27 10:30:00',
        'subject_type' => 'App\\Models\\Invoice',
        'subject_id' => 91,
    ]);

    $response = $this->actingAs(activityLogViewer())
        ->getJson(route('activity-logs.index', ['draw' => 1, 'start' => 0, 'length' => 10]));

    expect($response->json('data.0.occurred_at'))->toMatch('#^1[34]\d{2}/\d{2}/\d{2} \d{2}:\d{2}:\d{2}$#')
        ->and($response->json('data.0.record'))->toBe('Invoice #91');
});

it('escapes the action value inside the badge markup', function (): void {
    makeActivityLog(['action' => '<script>x</script>']);

    $response = $this->actingAs(activityLogViewer())
        ->getJson(route('activity-logs.index', ['draw' => 1, 'start' => 0, 'length' => 10]));

    $badge = $response->json('data.0.action_badge');

    expect($badge)->toStartWith('<span class="badge bg-secondary">')
        ->and($badge)->not->toContain('<script>')
        ->and($badge)->toContain('&lt;script&gt;');
});

it('keeps the activity log page behind authentication and permissions', function (): void {
    $this->get(route('activity-logs.index'))->assertRedirect(route('login'));

    $stranger = User::factory()->create([
        'is_active' => true,
        'can_access_erp' => true,
    ]);

    $this->actingAs($stranger)->get(route('activity-logs.index'))->assertForbidden();
});
