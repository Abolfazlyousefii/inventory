<?php

namespace Tests\Feature;

use App\Models\AccessPermission;
use App\Models\Customer;
use App\Models\CustomerFollowup;
use App\Models\CustomerInteraction;
use App\Models\SystemNotification;
use App\Models\User;
use App\Services\CustomerOwnership;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class CustomerFollowupTest extends TestCase
{
    use RefreshDatabase;

    public function test_writes_require_authentication_and_explicit_csrf_middleware(): void
    {
        $this->postJson('/commercial/my-customers/1/interactions', $this->payload())->assertUnauthorized();
        foreach (['commercial.customers.interactions.store', 'commercial.customers.followups.update'] as $name) {
            $route = app('router')->getRoutes()->getByName($name);
            $this->assertContains(\App\Http\Middleware\ProtectPersonnelCsrf::class, $route->gatherMiddleware());
        }
    }

    private function seller(): User
    {
        PermissionCatalog::syncToDatabase();
        $u = User::factory()->create(['is_active' => true, 'can_access_erp' => true]);
        $u->permissions()->attach(AccessPermission::where('key', 'preinvoices.create')->value('id'));

        return $u;
    }

    private function scenario(): array
    {
        $owner = $this->seller();
        $other = $this->seller();
        $customer = Customer::factory()->create();
        $customer->assignment()->create(['seller_id' => $owner->id, 'version' => 1]);

        return [$owner, $other, $customer];
    }

    private function payload(): array
    {
        return ['request_key' => (string) Str::uuid(), 'kind' => 'call', 'body' => 'تماس با مشتری و هماهنگی سفارش', 'followup_title' => 'تماس مجدد', 'due_date' => '۱۴۰۵/۰۹/۰۵', 'due_time' => '10:30'];
    }

    public function test_owner_creates_immutable_contact_and_followup_atomically_and_retry_is_safe(): void
    {
        [$owner,$other,$c] = $this->scenario();
        $payload = $this->payload();
        $url = '/commercial/my-customers/'.$c->id.'/interactions';
        $this->actingAs($other)->postJson($url, $payload)->assertNotFound();
        $this->actingAs($owner)->postJson($url, $payload)->assertCreated();
        $this->postJson($url, $payload)->assertOk();
        $this->assertDatabaseCount('customer_interactions', 1);
        $this->assertDatabaseCount('customer_followups', 1);
        $task = CustomerFollowup::firstOrFail();
        $this->assertSame($owner->id, $task->assigned_to);
        $this->assertSame('10:30', $task->due_at->format('H:i'));
        $this->assertSame('2026-11-26', $task->due_at->format('Y-m-d'));
        $this->postJson($url, array_replace($payload, ['body' => 'متن تغییرکرده']))->assertConflict();
        $this->get('/commercial/my-customers/'.$c->id.'?tab=activity')->assertOk()->assertSee($payload['body'])->assertSee($payload['followup_title']);
        $this->actingAs($other)->get('/commercial/my-customers/'.$c->id.'?tab=activity')->assertNotFound();
    }

    public function test_invalid_date_and_empty_note_create_no_records(): void
    {
        [$owner,,$c] = $this->scenario();
        $this->actingAs($owner);
        $url = '/commercial/my-customers/'.$c->id.'/interactions';
        $this->postJson($url, array_replace($this->payload(), ['due_date' => '1405/12/30']))->assertUnprocessable();
        $this->postJson($url, array_replace($this->payload(), ['body' => '   ']))->assertUnprocessable();
        $this->assertDatabaseCount('customer_interactions', 0);
        $this->assertDatabaseCount('customer_followups', 0);
    }

    public function test_status_is_scoped_versioned_and_completed_tasks_cannot_be_rewritten(): void
    {
        [$owner,$other,$c] = $this->scenario();
        $this->actingAs($owner)->postJson('/commercial/my-customers/'.$c->id.'/interactions', $this->payload())->assertCreated();
        $task = CustomerFollowup::firstOrFail();
        $url = '/commercial/my-customers/'.$c->id.'/followups/'.$task->id;
        $this->actingAs($other)->patchJson($url, ['status' => 'done', 'version' => 1])->assertNotFound();
        $this->actingAs($owner)->patchJson($url, ['status' => 'done', 'version' => 0])->assertConflict();
        $this->patchJson($url, ['status' => 'cancelled', 'version' => 1])->assertUnprocessable();
        $this->patchJson($url, ['status' => 'done', 'version' => 1, 'result' => 'هماهنگ شد'])->assertOk();
        $this->assertNotNull($task->fresh()->closed_at);
        $this->patchJson($url, ['status' => 'cancelled', 'version' => 2, 'result' => 'تغییر نظر'])->assertConflict();
        $this->assertDatabaseHas('activity_logs', ['action' => 'customer_followup_closed', 'subject_id' => $task->id]);
    }

    public function test_transfer_moves_open_tasks_only_and_old_owner_loses_access(): void
    {
        [$owner,$other,$c] = $this->scenario();
        $this->actingAs($owner)->postJson('/commercial/my-customers/'.$c->id.'/interactions', $this->payload())->assertCreated();
        $task = CustomerFollowup::firstOrFail();
        $admin = $this->seller();
        $admin->assignRole(Role::findOrCreate('Admin', 'web'));
        app(CustomerOwnership::class)->transfer($c, $other, $admin, 1, 'تغییر مسئول');
        $this->assertSame($other->id, $task->fresh()->assigned_to);
        $this->assertSame(2, $task->fresh()->version);
        $this->get('/commercial/followups?scope=all')->assertOk()->assertDontSee('تماس مجدد');
        $this->actingAs($other)->get('/commercial/followups?scope=all')->assertOk()->assertSee('تماس مجدد');
        $this->assertSame($owner->id, CustomerInteraction::first()->author_id);
    }

    public function test_internal_reminders_are_due_only_once_and_follow_current_owner(): void
    {
        [$owner,$other,$c] = $this->scenario();
        $this->actingAs($owner)->postJson('/commercial/my-customers/'.$c->id.'/interactions', $this->payload())->assertCreated();
        $task = CustomerFollowup::firstOrFail();
        $task->update(['due_at' => now()->subMinute()]);
        $this->artisan('commercial:followups-remind')->assertSuccessful();
        $this->artisan('commercial:followups-remind')->assertSuccessful();
        $this->assertSame(1, SystemNotification::where('type', 'customer_followup_due')->count());
        $this->assertDatabaseHas('notifications', ['type' => 'customer_followup_due', 'user_id' => $owner->id]);
        $admin = $this->seller();
        $admin->assignRole(Role::findOrCreate('Admin', 'web'));
        app(CustomerOwnership::class)->transfer($c, $other, $admin, 1, 'واگذاری پیگیری');
        $this->artisan('commercial:followups-remind')->assertSuccessful();
        $this->assertSame(2, SystemNotification::where('type', 'customer_followup_due')->count());
        $this->assertDatabaseHas('notifications', ['type' => 'customer_followup_due', 'user_id' => $other->id]);
    }

    public function test_plain_notes_require_no_task_and_financial_records_remain_untouched(): void
    {
        [$owner,,$c] = $this->scenario();
        $payload = $this->payload();
        unset($payload['followup_title'],$payload['due_date'],$payload['due_time']);
        $before = DB::table('customer_ledgers')->get()->toJson();
        $this->actingAs($owner)->postJson('/commercial/my-customers/'.$c->id.'/interactions', $payload)->assertCreated();
        $this->assertDatabaseCount('customer_followups', 0);
        $this->assertSame($before, DB::table('customer_ledgers')->get()->toJson());
        $this->assertDatabaseCount('invoices', 0);
        $this->assertDatabaseCount('stock_movements', 0);
    }

    public function test_contact_html_is_escaped_and_task_cannot_be_closed_under_another_customer(): void
    {
        [$owner,,$c] = $this->scenario();
        $otherCustomer = Customer::factory()->create();
        $otherCustomer->assignment()->create(['seller_id' => $owner->id]);
        $payload = array_replace($this->payload(), ['body' => '<script>alert(1)</script>', 'assigned_to' => 999, 'author_id' => 999]);
        $this->actingAs($owner)->postJson('/commercial/my-customers/'.$c->id.'/interactions', $payload)->assertCreated();
        $this->get('/commercial/my-customers/'.$c->id.'?tab=activity')->assertOk()->assertSee('&lt;script&gt;', false)->assertDontSee('<script>alert(1)</script>', false);
        $task = CustomerFollowup::firstOrFail();
        $this->assertSame($owner->id, $task->assigned_to);
        $this->patchJson('/commercial/my-customers/'.$otherCustomer->id.'/followups/'.$task->id, ['version' => 1, 'status' => 'done'])->assertNotFound();
        $c->update(['is_active' => false]);
        $this->postJson('/commercial/my-customers/'.$c->id.'/interactions', $this->payload())->assertUnprocessable();
        $owner->update(['is_active' => false]);
        $this->actingAs($owner->fresh());
        $this->get('/commercial/followups')->assertRedirect(route('login'));
    }
}
