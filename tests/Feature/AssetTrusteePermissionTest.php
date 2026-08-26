<?php

namespace Tests\Feature;

use App\Models\AssetDocument;
use App\Models\AssetPersonnel;
use App\Models\User;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AssetTrusteePermissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        PermissionCatalog::syncToDatabase();
        $this->artisan('permissions:sync')->assertSuccessful();
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_page_assets_alone_can_view_asset_pages_but_cannot_run_document_actions(): void
    {
        $user = $this->userWithRolePermissions(['page.assets']);
        $document = $this->assetDocument();

        $this->actingAs($user)->get(route('asset.hub'))->assertOk();
        $this->actingAs($user)->get(route('asset.documents.index'))->assertOk();
        $this->actingAs($user)->get(route('asset.documents.show', $document))->assertOk();

        $this->actingAs($user)->get(route('asset.documents.create'))->assertForbidden();
        $this->actingAs($user)->post(route('asset.documents.store'), [])->assertForbidden();
        $this->actingAs($user)->get(route('asset.documents.edit', $document))->assertForbidden();
        $this->actingAs($user)->put(route('asset.documents.update', $document), [])->assertForbidden();
        $this->actingAs($user)->patch(route('asset.documents.finalize', $document))->assertForbidden();
        $this->actingAs($user)->patch(route('asset.documents.cancel', $document))->assertForbidden();
        $this->actingAs($user)->get(route('asset.documents.print', $document))->assertForbidden();
        $this->actingAs($user)->get(route('asset.codes.search'))->assertForbidden();
        $this->actingAs($user)->get(route('asset.codes.find', ['code' => '1234']))->assertForbidden();
    }

    public function test_asset_document_action_permissions_unlock_only_their_own_operations(): void
    {
        $creator = $this->userWithRolePermissions(['page.assets', 'assets.documents.create']);
        $this->actingAs($creator)->get(route('asset.documents.create'))->assertOk();

        $editor = $this->userWithRolePermissions(['page.assets', 'assets.documents.edit']);
        $editableDocument = $this->assetDocument();
        $this->actingAs($editor)->get(route('asset.documents.edit', $editableDocument))->assertOk();

        $confirmer = $this->userWithRolePermissions(['page.assets', 'assets.documents.confirm']);
        $finalizeDocument = $this->assetDocument(['status' => AssetDocument::STATUS_DRAFT]);
        $this->actingAs($confirmer)
            ->from(route('asset.documents.show', $finalizeDocument))
            ->patch(route('asset.documents.finalize', $finalizeDocument))
            ->assertRedirect();
        $this->assertSame(AssetDocument::STATUS_FINALIZED, $finalizeDocument->fresh()->status);

        $canceller = $this->userWithRolePermissions(['page.assets', 'assets.documents.cancel']);
        $cancelDocument = $this->assetDocument(['status' => AssetDocument::STATUS_DRAFT]);
        $this->actingAs($canceller)
            ->from(route('asset.documents.show', $cancelDocument))
            ->patch(route('asset.documents.cancel', $cancelDocument))
            ->assertRedirect();
        $this->assertSame(AssetDocument::STATUS_CANCELLED, $cancelDocument->fresh()->status);

        $printer = $this->userWithRolePermissions(['page.assets', 'assets.documents.print']);
        $printDocument = $this->assetDocument();
        $this->actingAs($printer)->get(route('asset.documents.print', $printDocument))->assertOk();

        $searcher = $this->userWithRolePermissions(['page.assets', 'assets.codes.search']);
        $this->actingAs($searcher)->get(route('asset.codes.search'))->assertOk();
    }

    public function test_asset_action_permission_without_page_assets_cannot_enter_asset_workflow(): void
    {
        $user = $this->userWithRolePermissions(['assets.documents.create']);

        $this->actingAs($user)->get(route('asset.documents.create'))->assertForbidden();
    }

    private function userWithRolePermissions(array $keys): User
    {
        $role = Role::findOrCreate('AssetPermissionRole-'.str_replace('.', '-', implode('-', $keys)).'-'.bin2hex(random_bytes(3)), 'web');

        foreach ($keys as $key) {
            $role->givePermissionTo($this->permission($key));
        }

        $user = User::factory()->create();
        $user->assignRole($role);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        return $user;
    }

    private function assetDocument(array $overrides = []): AssetDocument
    {
        $trustee = User::factory()->create(['name' => 'امین تست']);
        $personnel = AssetPersonnel::query()->create([
            'user_id' => $trustee->id,
            'user_name_snapshot' => $trustee->name,
            'full_name' => $trustee->name,
            'personnel_code' => 'TST-'.$trustee->id,
            'department' => 'انبار',
            'position' => 'امین اموال',
            'mobile' => '0912000'.str_pad((string) $trustee->id, 4, '0', STR_PAD_LEFT),
            'is_active' => true,
        ]);

        return AssetDocument::query()->create(array_merge([
            'document_number' => 'TST-'.str_pad((string) ($trustee->id), 4, '0', STR_PAD_LEFT),
            'document_date' => now()->toDateString(),
            'personnel_id' => $personnel->id,
            'trustee_user_id' => $trustee->id,
            'trustee_name_snapshot' => $trustee->name,
            'status' => AssetDocument::STATUS_DRAFT,
            'description' => 'سند تست دسترسی امین اموال',
            'created_by' => $trustee->id,
            'updated_by' => $trustee->id,
        ], $overrides));
    }

    private function permission(string $key): Permission
    {
        return Permission::query()->where('key', $key)->firstOrFail();
    }
}
