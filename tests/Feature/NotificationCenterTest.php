<?php

namespace Tests\Feature;

use App\Models\SystemNotification;
use App\Models\User;
use App\Services\NotificationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class NotificationCenterTest extends TestCase
{
    use RefreshDatabase;

    private function user(string $role): User
    {
        $user = User::factory()->create(['is_active' => true, 'can_access_erp' => true]);
        $user->assignRole(Role::findOrCreate($role, 'web'));
        return $user;
    }

    public function test_role_reads_are_independent_and_private_notifications_are_not_visible_to_admin(): void
    {
        $first = $this->user('finance');
        $second = $this->user('finance');
        $admin = $this->user('admin');
        $service = app(NotificationService::class);
        $shared = $service->notifyRole('finance', ['title' => 'Shared', 'unique_key' => 'shared']);
        $private = $service->notifyUser($first, ['title' => 'Private', 'unique_key' => 'private']);

        $this->actingAs($first)->getJson(route('notifications.latest'))->assertJsonPath('count', 2);
        $this->postJson(route('notifications.read', $shared))->assertOk()->assertJsonPath('count', 1);
        $this->getJson(route('notifications.latest'))->assertJsonMissing(['id' => $shared->id]);
        $this->getJson(route('notifications.latest', ['filter' => 'read']))->assertJsonPath('items.0.id', $shared->id);
        $this->actingAs($second)->getJson(route('notifications.unread-count'))->assertJsonPath('count', 1);
        $this->postJson(route('notifications.read', $private))->assertForbidden();
        $this->actingAs($admin)->getJson(route('notifications.unread-count'))->assertJsonPath('count', 0);
        $this->postJson(route('notifications.read', $private))->assertForbidden();
        $this->post(route('notifications.open', $private))->assertForbidden();
    }

    public function test_read_all_preserves_history_and_new_events_and_paginates_newest_first(): void
    {
        $user = $this->user('finance');
        $other = $this->user('finance');
        $service = app(NotificationService::class);
        for ($i = 0; $i < 12; $i++) {
            $service->notifyUser($user, ['title' => "Item $i", 'unique_key' => "item:$i"]);
        }
        $shared = $service->notifyRole('finance', ['title' => 'Shared', 'unique_key' => 'role-event']);
        $this->actingAs($user)->getJson(route('notifications.latest', ['per_page' => 5]))
            ->assertJsonPath('count', 13)->assertJsonPath('items.0.id', $shared->id)->assertJsonPath('last_page', 3)->assertJsonCount(5, 'items');
        $this->getJson(route('notifications.latest', ['per_page' => 5, 'page' => 3]))->assertJsonCount(3, 'items');
        $this->postJson(route('notifications.read-all'))->assertJsonPath('count', 0);
        $this->getJson(route('notifications.latest'))->assertJsonCount(0, 'items');
        $this->getJson(route('notifications.latest', ['filter' => 'read']))->assertJsonPath('total', 13);
        $this->assertDatabaseCount('notifications', 13);
        $new = $service->notifyUser($user, ['title' => 'New', 'unique_key' => 'new']);
        $this->getJson(route('notifications.latest'))->assertJsonPath('count', 1)->assertJsonPath('items.0.id', $new->id);
        $this->actingAs($other)->getJson(route('notifications.unread-count'))->assertJsonPath('count', 1);
    }

    public function test_event_keys_are_idempotent_per_recipient_and_open_marks_read(): void
    {
        $first = $this->user('finance');
        $second = $this->user('finance');
        $service = app(NotificationService::class);
        $payload = ['type' => 'personnel.workflow', 'title' => 'Event', 'unique_key' => 'same-event'];
        $one = $service->notifyUser($first, $payload);
        $two = $service->notifyUser($second, $payload);
        $this->assertNotSame($one->id, $two->id);
        $this->actingAs($first)->post(route('notifications.open', $one))->assertRedirect(route('notifications.index'));
        $this->assertSame($one->id, $service->notifyUser($first, $payload)->id);
        $this->assertDatabaseCount('notifications', 2);
        $this->getJson(route('notifications.unread-count'))->assertJsonPath('count', 0);
        $this->getJson(route('notifications.latest', ['filter' => 'read']))->assertJsonPath('items.0.category', 'پرسنل');
    }

    public function test_legacy_read_history_is_preserved_and_unsafe_destinations_are_rejected(): void
    {
        $user = $this->user('finance');
        $service = app(NotificationService::class);
        $legacy = SystemNotification::create([
            'role' => 'Finance', 'type' => 'preinvoice_submitted', 'title' => 'Old',
            'unique_key' => 'old-event', 'read_at' => now()->subDay(),
        ]);
        $this->assertSame($legacy->id, $service->notifyRole('finance', ['unique_key' => 'old-event', 'title' => 'Retry'])->id);
        $this->getJson(route('notifications.latest'))->assertUnauthorized();
        $this->actingAs($user)->getJson(route('notifications.latest', ['filter' => 'read']))
            ->assertJsonPath('items.0.id', $legacy->id)->assertJsonPath('count', 0);
        $unsafe = $service->notifyUser($user, ['title' => 'Unsafe URL', 'link' => '//external.example/path', 'unique_key' => 'unsafe-url']);
        $this->post(route('notifications.open', $unsafe))->assertRedirect(route('notifications.index'));
        $this->assertDatabaseCount('notifications', 2);
    }


    public function test_one_role_event_is_visible_once_to_users_with_multiple_matching_roles(): void
    {
        $both = $this->user('finance');
        $warehouse = $this->user('warehouse');
        $both->assignRole(Role::findOrCreate('warehouse', 'web'));
        $service = app(NotificationService::class);
        $payload = ['title' => 'Shared event', 'unique_key' => 'cross-team'];
        $notification = $service->notifyRole(['Finance', 'warehouse'], $payload);
        $this->assertSame($notification->id, $service->notifyRole('warehouse', $payload)->id);
        $this->assertDatabaseCount('notifications', 1);
        $this->actingAs($both)->getJson(route('notifications.latest'))->assertJsonPath('count', 1)->assertJsonCount(1, 'items');
        $this->postJson(route('notifications.read', $notification))->assertJsonPath('count', 0);
        $this->actingAs($warehouse)->getJson(route('notifications.latest'))->assertJsonPath('count', 1);
    }

}
