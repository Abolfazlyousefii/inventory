<?php

namespace Tests\Feature;

use App\Http\Middleware\ProtectPersonnelCsrf;
use App\Models\AccessPermission;
use App\Models\PersonnelEntry;
use App\Models\User;
use App\Support\PageAccessCatalog;
use App\Support\PermissionCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Morilog\Jalali\Jalalian;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PersonnelWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private function person(array $fields = [], array $permissions = []): User
    {
        PermissionCatalog::syncToDatabase();
        $user = User::factory()->create($fields + ['is_active' => true, 'can_access_erp' => true]);
        foreach ($permissions as $key) {
            $user->permissions()->attach(AccessPermission::where('key', $key)->value('id'));
        }

        return $user;
    }

    private function team(): array
    {
        $executive = $this->person(['department' => 'عملیات', 'organization_level' => 'executive_manager'], ['personnel.leaves.review', 'personnel.reports.review']);
        $internal = $this->person(['department' => 'عملیات', 'manager_id' => $executive->id, 'organization_level' => 'internal_manager'], ['personnel.leaves.review', 'personnel.reports.review']);
        $supervisor = $this->person(['department' => 'فروش', 'manager_id' => $internal->id, 'organization_level' => 'supervisor'], ['personnel.leaves.review', 'personnel.reports.review']);
        $staff = $this->person(['department' => 'فروش', 'manager_id' => $supervisor->id], ['personnel.leaves.use', 'personnel.reports.use']);
        $substitute = $this->person(['department' => 'فروش', 'manager_id' => $supervisor->id], ['personnel.leaves.use']);

        return [$staff, $substitute, $supervisor, $internal, $executive];
    }

    private function leave(User $substitute): array
    {
        return ['submission_key' => (string) Str::uuid(), 'leave_type' => 'استحقاقی', 'leave_unit' => 'روزانه', 'start_date' => Jalalian::fromCarbon(now()->addDays(4))->format('Y/m/d'), 'end_date' => Jalalian::fromCarbon(now()->addDays(4))->format('Y/m/d'), 'substitute_user_id' => $substitute->id, 'reason' => 'درخواست آزمایشی'];
    }

    public function test_leave_snapshots_route_and_only_current_approver_can_decide_once(): void
    {
        [$staff,$substitute,$supervisor,$internal,$executive] = $this->team();
        $data = $this->leave($substitute);
        $this->actingAs($staff)->postJson('/personnel/leaves', $data)->assertCreated();
        $entry = PersonnelEntry::firstOrFail();
        $this->assertSame([$substitute->id, $supervisor->id, $internal->id, $executive->id], $entry->steps()->pluck('approver_id')->all());
        // Chart membership is presentation-only, including for a pending approver.
        $supervisor->update(['show_in_organization_chart' => false]);
        $this->assertSame([$substitute->id, $supervisor->id, $internal->id, $executive->id], $entry->steps()->pluck('approver_id')->all());
        $this->postJson('/personnel/leaves', $data)->assertOk();
        $this->assertSame(1, PersonnelEntry::count());
        $url = '/personnel/leaves/'.$entry->id.'/decision';
        $this->actingAs($supervisor)->postJson($url, ['action' => 'approve', 'version' => 1])->assertForbidden();
        $this->actingAs($substitute)->postJson($url, ['action' => 'approve', 'version' => 1])->assertOk();
        $this->postJson($url, ['action' => 'approve', 'version' => 1])->assertStatus(409);
        $staff->update(['manager_id' => $executive->id]);
        $this->actingAs($supervisor)->postJson($url, ['action' => 'approve', 'version' => 2])->assertOk();
        $this->actingAs($internal)->postJson($url, ['action' => 'approve', 'version' => 3])->assertOk();
        $this->actingAs($executive)->postJson($url, ['action' => 'approve', 'version' => 4])->assertOk();
        $this->assertSame('approved', $entry->fresh()->status);
        $this->assertSame(5, $entry->events()->count());
    }

    public function test_leave_rules_overlap_invalid_substitute_and_no_self_approval(): void
    {
        [$staff,$substitute] = $this->team();
        $data = $this->leave($substitute);
        $this->actingAs($staff)->postJson('/personnel/leaves', $data + ['ignored' => 'x'])->assertCreated();
        $data['submission_key'] = (string) Str::uuid();
        $this->postJson('/personnel/leaves', $data)->assertUnprocessable()->assertJsonValidationErrors('start_date');
        $data['substitute_user_id'] = $staff->id;
        $this->postJson('/personnel/leaves', $data)->assertUnprocessable()->assertJsonValidationErrors('substitute_user_id');
        $data['substitute_user_id'] = $substitute->id;
        $data['leave_type'] = 'اضطراری';
        $this->postJson('/personnel/leaves', $data)->assertUnprocessable()->assertJsonValidationErrors('leave_unit');
        $outsider = $this->person(['department' => 'انبار']);
        $data['substitute_user_id'] = $outsider->id;
        $data['leave_type'] = 'استحقاقی';
        $this->postJson('/personnel/leaves', $data)->assertUnprocessable()->assertJsonValidationErrors('substitute_user_id');
    }

    public function test_report_draft_submission_scope_private_attachment_feedback_and_revision(): void
    {
        Storage::fake('local');
        [$staff,,$supervisor,$internal,$executive] = $this->team();
        $outsider = $this->person([], ['personnel.reports.review']);
        $data = ['submission_key' => (string) Str::uuid(), 'title' => 'گزارش تست', 'description' => 'فعالیت روزانه', 'action' => 'draft', 'attachments' => [UploadedFile::fake()->create('report.pdf', 10, 'application/pdf')]];
        $this->actingAs($staff)->postJson('/personnel/reports', $data)->assertCreated();
        $entry = PersonnelEntry::firstOrFail();
        $attachment = $entry->attachments()->firstOrFail();
        $url = '/personnel/reports/'.$entry->id;
        $this->actingAs($supervisor)->getJson($url)->assertForbidden();
        $this->actingAs($staff)->putJson($url, ['version' => 1, 'title' => 'گزارش تست', 'description' => 'فعالیت روزانه', 'action' => 'submit'])->assertOk();
        $this->assertSame([$supervisor->id, $internal->id, $executive->id], $entry->steps()->pluck('approver_id')->all());
        $this->actingAs($executive)->getJson($url)->assertOk();
        $this->actingAs($outsider)->getJson($url)->assertForbidden();
        $fileUrl = '/personnel/attachments/'.$attachment->id;
        $this->get($fileUrl)->assertForbidden();
        $this->get('/personnel/reports?tab=team')->assertOk()->assertDontSee('گزارش تست');
        $this->actingAs($supervisor)->get($fileUrl)->assertOk();
        $this->postJson($url.'/feedback', ['version' => 2, 'action' => 'revision', 'body' => 'جزئیات را تکمیل کنید'])->assertOk();
        $this->actingAs($staff)->putJson($url, ['version' => 3, 'title' => 'گزارش تست', 'description' => 'فعالیت تکمیل شده', 'action' => 'submit'])->assertOk();
        $this->actingAs($internal)->postJson($url.'/feedback', ['version' => 4, 'action' => 'read', 'body' => 'بررسی شد', 'rating' => 4])->assertOk();
        $this->assertSame('read', $entry->fresh()->status);
        $this->assertSame(5, $entry->events()->count());
        Storage::disk('local')->assertExists($attachment->path);
    }

    public function test_access_is_not_granted_by_organizational_title_or_user_management_permission(): void
    {
        $user = $this->person(['organization_level' => 'executive_manager'], ['page.roles']);
        $this->actingAs($user)->get('/personnel/leaves')->assertForbidden();
        $this->get('/personnel/reports')->assertForbidden();
    }

    public function test_forms_render_and_substitute_inbox_does_not_reveal_other_requests(): void
    {
        [$staff,$substitute,,$internal] = $this->team();
        $this->actingAs($staff)->get('/personnel/leaves/create')->assertOk()->assertSee('جایگزین هم‌واحد');
        $this->get('/personnel/reports/create')->assertOk()->assertSee('ذخیره پیش‌نویس');
        $this->postJson('/personnel/leaves', $this->leave($substitute))->assertCreated();
        $entry = PersonnelEntry::firstOrFail();
        $this->actingAs($substitute)->get('/personnel/leaves?tab=team')->assertOk()->assertSee('استحقاقی روزانه');
        $this->get('/personnel/leaves/'.$entry->id)->assertOk()->assertSee('تأیید این مرحله');
        $this->get('/personnel/reports/'.$entry->id)->assertNotFound();
        $this->actingAs($internal)->get('/personnel/leaves?tab=team')->assertOk()->assertSee('استحقاقی روزانه');
    }

    public function test_emergency_quota_is_jalali_monthly_and_cancelled_requests_do_not_consume_it(): void
    {
        [$staff,$substitute] = $this->team();
        $data = $this->leave($substitute);
        $data['leave_type'] = 'اضطراری';
        $data['leave_unit'] = 'ساعتی';
        $data['start_time'] = '08:00';
        $data['end_time'] = '12:01';
        $this->actingAs($staff)->postJson('/personnel/leaves', $data)->assertUnprocessable()->assertJsonValidationErrors('end_time');
        $data['end_time'] = '12:00';
        foreach ([10, 11, 12] as $day) {
            $data['submission_key'] = (string) Str::uuid();
            $data['start_date'] = $data['end_date'] = '1405/10/'.$day;
            $this->postJson('/personnel/leaves', $data)->assertCreated();
        }
        $data['submission_key'] = (string) Str::uuid();
        $data['start_date'] = $data['end_date'] = '1405/10/13';
        $this->postJson('/personnel/leaves', $data)->assertUnprocessable()->assertJsonValidationErrors('leave_type');
        $entry = PersonnelEntry::firstOrFail();
        $this->postJson('/personnel/leaves/'.$entry->id.'/decision', ['version' => 1, 'action' => 'cancel'])->assertOk();
        $this->postJson('/personnel/leaves', $data)->assertCreated();
    }

    public function test_reports_reject_unchanged_revision_duplicate_daily_submit_and_unsafe_attachment(): void
    {
        Storage::fake('local');
        [$staff,,$supervisor] = $this->team();
        $data = ['submission_key' => (string) Str::uuid(), 'description' => 'گزارش اولیه', 'action' => 'submit'];
        $this->actingAs($staff)->postJson('/personnel/reports', $data)->assertCreated();
        $entry = PersonnelEntry::firstOrFail();
        $data['submission_key'] = (string) Str::uuid();
        $this->postJson('/personnel/reports', $data)->assertUnprocessable()->assertJsonValidationErrors('description');
        $data['action'] = 'draft';
        $data['attachments'] = [UploadedFile::fake()->create('bad.php', 1, 'application/x-php')];
        $this->postJson('/personnel/reports', $data)->assertUnprocessable()->assertJsonValidationErrors('attachments.0');
        $url = '/personnel/reports/'.$entry->id;
        $this->actingAs($supervisor)->postJson($url.'/feedback', ['version' => 1, 'action' => 'revision', 'body' => 'تکمیل شود'])->assertOk();
        $this->actingAs($staff)->putJson($url, ['version' => 2, 'description' => 'گزارش اولیه', 'action' => 'submit'])->assertUnprocessable();
        $this->putJson($url, ['version' => 2, 'description' => 'متن جدید', 'action' => 'draft'])->assertOk();
        $this->putJson($url, ['version' => 3, 'description' => 'متن جدید', 'action' => 'submit'])->assertOk();
        $this->assertSame(1, PersonnelEntry::count());
    }

    public function test_leave_rejection_requires_reason_and_missing_or_unprivileged_path_rolls_back(): void
    {
        [$staff,$substitute,$supervisor] = $this->team();
        $this->actingAs($staff)->postJson('/personnel/leaves', $this->leave($substitute))->assertCreated();
        $entry = PersonnelEntry::firstOrFail();
        $url = '/personnel/leaves/'.$entry->id.'/decision';
        $this->actingAs($substitute)->postJson($url, ['action' => 'reject', 'version' => 1])->assertUnprocessable();
        $this->assertSame('pending', $entry->steps()->first()->status);
        $this->postJson($url, ['action' => 'reject', 'version' => 1, 'body' => 'امکان جایگزینی ندارم'])->assertOk();
        $this->assertSame('rejected', $entry->fresh()->status);
        $this->actingAs($supervisor)->postJson($url, ['action' => 'approve', 'version' => 2])->assertForbidden();
        $supervisor->permissions()->detach();
        $this->actingAs($staff)->postJson('/personnel/leaves', $this->leave($substitute))->assertUnprocessable()->assertJsonValidationErrors('organization');
        $this->assertSame(1, PersonnelEntry::count());
        $this->assertSame(4, $entry->steps()->count());
    }

    public function test_personnel_permissions_can_be_managed_through_roles_without_widening_team_scope(): void
    {
        $admin = $this->person();
        $admin->assignRole(Role::findOrCreate('Admin', 'web'));
        $role = Role::findOrCreate('PersonnelTest', 'web');
        $permission = AccessPermission::where('key', 'personnel.reports.review')->firstOrFail();
        $this->actingAs($admin)->get('/admin/roles/'.$role->id.'/edit')->assertOk()->assertSee('data-permission-key="personnel.reports.review"', false);
        $this->put('/admin/roles/'.$role->id, ['name' => $role->name, 'permissions' => [$permission->id]])->assertRedirect();
        $this->assertTrue($role->fresh()->permissions->contains('key', 'personnel.reports.review'));
    }

    public function test_leave_has_one_unit_stage_while_reports_follow_the_full_management_chain(): void
    {
        [$staff,$substitute,$supervisor,$internal,$executive] = $this->team();
        $unitManager = $this->person(['manager_id' => $internal->id, 'organization_level' => 'department_manager'], ['personnel.leaves.review', 'personnel.reports.review']);
        $supervisor->update(['manager_id' => $unitManager->id]);
        $this->actingAs($staff)->postJson('/personnel/leaves', $this->leave($substitute))->assertCreated();
        $this->assertSame([$substitute->id, $supervisor->id, $internal->id, $executive->id], PersonnelEntry::where('kind', 'leave')->firstOrFail()->steps()->pluck('approver_id')->all());
        $this->postJson('/personnel/reports', ['submission_key' => (string) Str::uuid(), 'description' => 'گزارش زنجیره', 'action' => 'submit'])->assertCreated();
        $this->assertSame([$supervisor->id, $unitManager->id, $internal->id, $executive->id], PersonnelEntry::where('kind', 'report')->firstOrFail()->steps()->pluck('approver_id')->all());
        foreach (Route::getRoutes() as $route) {
            if (str_starts_with($route->getName() ?? '', 'personnel.')) {
                $this->assertContains($route->getName(), PageAccessCatalog::authenticatedRouteAllowlist());
            }
        }
    }

    public function test_personnel_csrf_protection_ignores_the_legacy_global_exemption(): void
    {
        $middleware = new class(app(), app('encrypter')) extends ProtectPersonnelCsrf
        {
            protected function runningUnitTests()
            {
                return false;
            }

            protected function addCookieToResponse($request, $response)
            {
                return $response;
            }
        };
        $session = app('session')->driver();
        $session->start();
        $session->regenerateToken();
        $request = Request::create('/personnel/reports', 'POST');
        $request->setLaravelSession($session);
        try {
            $middleware->handle($request, fn () => response('unsafe'));
            $this->fail('Missing token accepted');
        } catch (TokenMismatchException) {
            $this->assertTrue(true);
        }
        $request->request->set('_token', $session->token());
        $this->assertSame('safe', $middleware->handle($request, fn () => response('safe'))->getContent());
    }

    public function test_leave_exports_share_list_scope_filters_and_escape_spreadsheet_formulas(): void
    {
        [$staff,$substitute,$supervisor] = $this->team();
        $staff->update(['name' => '=FORMULA()']);
        $this->actingAs($staff)->postJson('/personnel/leaves', $this->leave($substitute))->assertCreated();
        $entry = PersonnelEntry::firstOrFail();
        $entry->update(['start_date' => '2026-12-22', 'end_date' => '2026-12-24', 'status' => 'approved', 'description' => '=HYPERLINK("bad")']);
        $outsider = $this->person([], ['personnel.leaves.use', 'personnel.leaves.review']);
        $other = PersonnelEntry::create(['kind' => 'leave', 'user_id' => $outsider->id, 'submission_key' => (string) Str::uuid(), 'title' => 'محرمانه خارج تیم', 'start_date' => '2026-12-22', 'end_date' => '2026-12-24', 'status' => 'approved', 'payload' => ['leave_type' => 'استحقاقی', 'leave_unit' => 'روزانه']]);
        $url = '/personnel/leaves/export?tab=team&status=approved&from=1405/10/02&to=1405/10/02';
        $csv = $this->actingAs($supervisor)->get($url)->assertOk()->assertDownload()->streamedContent();
        $this->assertStringContainsString("'=FORMULA()", $csv);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString('محرمانه خارج تیم', $csv);
        $this->assertStringContainsString('1405/10/01', $csv);
        $this->get('/personnel/leaves/print?tab=team&status=approved&from=1405/10/02&to=1405/10/02')->assertOk()->assertSee('=FORMULA()')->assertDontSee('محرمانه خارج تیم')->assertSee('۱۴۰۵');
        $empty = $this->get('/personnel/leaves/export?tab=team&status=rejected')->assertOk()->streamedContent();
        $this->assertStringNotContainsString('FORMULA', $empty);
        $own = $this->actingAs($outsider)->get('/personnel/leaves/export?tab=team')->assertOk()->streamedContent();
        $this->assertStringNotContainsString('FORMULA', $own);
        $this->getJson('/personnel/leaves/export?from=1405/10/10&to=1405/10/01')->assertUnprocessable();
        $this->actingAs($this->person())->get('/personnel/leaves/export')->assertForbidden();
    }
    public function test_registration_forms_load_inside_the_list_workspace(): void
    {
        $user = $this->person([], ['personnel.leaves.use', 'personnel.reports.use']);
        foreach (['leaves', 'reports'] as $module) {
            $this->actingAs($user)->get('/personnel/'.$module)->assertOk()->assertSee('data-hr-compose', false)->assertSee('id="hrComposeDialog"', false);
            $this->get('/personnel/'.$module.'/create', ['X-Requested-With' => 'XMLHttpRequest'])
                ->assertOk()->assertSee('data-hr-save', false)->assertSee('name="submission_key"', false)
                ->assertDontSee('<html', false)->assertDontSee('id="hrDateDialog"', false);
        }
        $this->actingAs($this->person())->get('/personnel/leaves/create', ['X-Requested-With' => 'XMLHttpRequest'])->assertForbidden();
    }

}
