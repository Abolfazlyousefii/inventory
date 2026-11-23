<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\PersonnelEntry;
use App\Models\User;
use App\Services\CrmMigrationRehearsal;
use App\Services\Personnel\OrganizationHierarchy;
use App\Services\Personnel\PersonnelAccess;
use App\Services\Personnel\PersonnelWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class CrmMigrationRehearsalTest extends TestCase
{
    use RefreshDatabase;

    private function snapshot(): array
    {
        return ['schema_version' => 1, 'source' => 'ariya-crm', 'flags' => ['organization_flow' => true, 'report_review' => false], 'manager_chains' => [101 => [102]], 'tables' => [
            'users' => [['id' => 101, 'name' => 'Staff'], ['id' => 102, 'name' => 'Manager']],
            'customers' => [['id' => 201, 'name' => 'Customer', 'phone' => '09121112233', 'user_id' => 101, 'created_at' => '2026-01-01 10:00:00', 'updated_at' => '2026-01-01 10:00:00']],
            'customer_notes' => [['id' => 301, 'customer_id' => 201, 'user_id' => 102, 'title' => 'Contact', 'content' => 'Original contact', 'created_at' => '2026-01-02 10:00:00', 'updated_at' => '2026-01-02 10:00:00']],
            'leaves' => [['id' => 401, 'user_id' => 101, 'substitute_user_id' => null, 'leave_type' => 'استحقاقی', 'leave_unit' => 'روزانه', 'start_date' => '2026-01-01', 'end_date' => '2026-01-02', 'start_time' => '00:00:00', 'end_time' => '23:59:00', 'reason' => 'Historical request', 'status' => 'pending', 'approval_route' => 'staged', 'assigned_approver_id' => 102, 'deleted_at' => null, 'created_at' => '2025-12-01 10:00:00', 'updated_at' => '2025-12-01 10:00:00']],
            'leave_workflow_steps' => [['id' => 501, 'leave_id' => 401, 'position' => 1, 'stage' => 'executive', 'approver_id' => 102, 'status' => 'pending', 'decided_at' => null]],
            'leave_workflow_events' => [], 'leave_approvers' => [['user_id' => 102]],
            'reports' => [['id' => 601, 'user_id' => 101, 'title' => 'Work', 'description' => 'Original work report', 'status' => 'read', 'submitted_at' => '2026-01-01 12:00:00', 'feedback' => 'Original feedback', 'rating' => 4, 'successful_calls' => 5, 'unsuccessful_calls' => 2, 'created_at' => '2026-01-01 10:00:00', 'updated_at' => '2026-01-01 12:00:00']],
            'report_attachments' => [], 'report_comments' => [], 'report_review_workflows' => [], 'reminders' => [['id' => 701, 'user_id' => 101, 'title' => 'Personal reminder', 'repeat' => 'daily', 'seen' => 0]],
        ]];
    }

    private function people(): array
    {
        return [User::factory()->create(['crm_user_id' => '101', 'is_active' => true, 'can_access_erp' => true]), User::factory()->create(['crm_user_id' => '102', 'is_active' => true, 'can_access_erp' => true])];
    }

    public function test_real_statuses_history_author_assignment_and_idempotency_are_preserved(): void
    {
        [$staff,$manager] = $this->people();
        $source = $this->snapshot();
        $import = app(CrmMigrationRehearsal::class);
        $plan = $import->plan($source);
        $this->assertSame([], $plan['errors']);
        $import->apply($source, $plan['hash']);
        $leave = PersonnelEntry::where('kind', 'leave')->firstOrFail();
        $this->assertSame('pending', $leave->status);
        $this->assertSame($manager->id, (int) $leave->steps->first()->approver_id);
        $this->assertSame('executive_manager', $leave->steps->first()->stage);
        $this->assertSame('read', PersonnelEntry::where('kind', 'report')->value('status'));
        $this->assertSame('Original feedback', PersonnelEntry::where('kind', 'report')->first()->payload['crm_feedback']);
        $this->assertDatabaseHas('customer_assignments', ['seller_id' => $staff->id]);
        $this->assertDatabaseHas('customer_interactions', ['author_id' => $manager->id, 'body' => "Contact\nOriginal contact"]);
        $this->assertDatabaseHas('crm_import_records', ['source_table' => 'reminders', 'source_id' => 701]);
        $import->apply($source, $import->plan($source)['hash']);
        $this->assertSame(2, PersonnelEntry::count());
        $this->assertSame(1, Customer::count());
        $this->assertSame(0, DB::table('notifications')->count());
    }

    public function test_conflicting_customer_identity_and_changed_snapshot_stop_before_any_writes(): void
    {
        $this->people();
        $source = $this->snapshot();
        Customer::factory()->create(['crm_customer_id' => '201', 'mobile' => '09999999999']);
        $import = app(CrmMigrationRehearsal::class);
        $plan = $import->plan($source);
        $this->assertContains('customer_identity_conflict:201', $plan['errors']);
        try {
            $import->apply($source, $plan['hash']);
            $this->fail('Conflict must stop import');
        } catch (ValidationException) {
        }
        $this->assertSame(0, PersonnelEntry::count());
        $this->assertSame(0, DB::table('crm_import_records')->count());
    }

    public function test_legacy_pending_pool_is_preserved_without_fabricating_previous_approvals(): void
    {
        [$staff,$manager] = $this->people();
        $source = $this->snapshot();
        $source['tables']['leaves'][0]['approval_route'] = null;
        $source['tables']['leaves'][0]['status'] = 'internal_approved';
        $source['tables']['leaves'][0]['assigned_approver_id'] = null;
        $source['tables']['leave_workflow_steps'] = [];
        $import = app(CrmMigrationRehearsal::class);
        $import->apply($source, $import->plan($source)['hash']);
        $entry = PersonnelEntry::where('kind', 'leave')->firstOrFail();
        $this->assertSame('pending', $entry->status);
        $this->assertSame('internal_approved', $entry->payload['crm_status']);
        $this->assertSame('legacy_pool', $entry->steps->first()->stage);
        $this->assertSame(0, $entry->steps()->where('status', 'approved')->count());
        $access = \Mockery::mock(PersonnelAccess::class)->makePartial();
        $access->shouldReceive('canReview')->andReturn(true);
        $workflow = new PersonnelWorkflow(app(OrganizationHierarchy::class), $access);
        $workflow->decide($entry, $manager, 'approve', 1, null);
        $this->assertSame('approved', $entry->fresh()->status);
    }

    public function test_unknown_status_or_missing_user_blocks_import_and_deleted_requests_remain_archived(): void
    {
        $this->people();
        $import = app(CrmMigrationRehearsal::class);
        $source = $this->snapshot();
        $source['tables']['leaves'][0]['status'] = 'unexpected';
        $this->assertNotEmpty($import->plan($source)['errors']);
        $source = $this->snapshot();
        $source['tables']['leaves'][0]['deleted_at'] = '2026-01-03 10:00:00';
        $import->apply($source, $import->plan($source)['hash']);
        $entry = PersonnelEntry::where('kind', 'leave')->firstOrFail();
        $this->assertSame('cancelled', $entry->status);
        $this->assertNotEmpty($entry->payload['crm_deleted_at']);
        $this->assertSame(0, $entry->steps()->where('status', 'pending')->count());
    }

    public function test_source_change_or_stale_preview_does_not_overwrite_local_history(): void
    {
        $this->people();
        $import = app(CrmMigrationRehearsal::class);
        $source = $this->snapshot();
        $plan = $import->plan($source);
        $source['tables']['reports'][0]['description'] = 'Changed after preview';
        try {
            $import->apply($source, $plan['hash']);
            $this->fail('Stale preview accepted');
        } catch (ValidationException) {
        }
        $this->assertSame(0, PersonnelEntry::count());
        $import->apply($source, $import->plan($source)['hash']);
        $source['tables']['reports'][0]['description'] = 'A second source revision';
        $this->assertContains('changed_imported_record:reports:601', $import->plan($source)['errors']);
        $this->assertSame('Changed after preview', PersonnelEntry::where('kind', 'report')->value('description'));
    }

    public function test_missing_file_must_be_explicitly_reported_and_never_gets_a_broken_download(): void
    {
        $this->people();
        $source = $this->snapshot();
        $source['tables']['report_attachments'] = [['id' => 801, 'report_id' => 601, 'file_path' => 'report_attachments/missing.jpg', 'type' => 'image/jpeg', 'created_at' => '2026-01-01 10:00:00', 'updated_at' => '2026-01-01 10:00:00']];
        $import = app(CrmMigrationRehearsal::class);
        $this->assertContains('missing_attachment:801', $import->plan($source)['errors']);
        $source['attachment_inventory'] = ['report_attachments/missing.jpg' => ['missing' => true]];
        $import->apply($source, $import->plan($source)['hash']);
        $this->assertSame([801], PersonnelEntry::where('kind', 'report')->first()->payload['crm_missing_attachments']);
        $this->assertSame(0, DB::table('personnel_attachments')->count());
        $this->assertDatabaseHas('crm_import_records', ['source_table' => 'report_attachments', 'source_id' => 801]);
    }

    public function test_two_legacy_approvers_are_alternatives_and_an_outsider_cannot_decide(): void
    {
        [$staff,$first] = $this->people();
        $second = User::factory()->create(['crm_user_id' => '103', 'is_active' => true, 'can_access_erp' => true]);
        $outsider = User::factory()->create(['is_active' => true, 'can_access_erp' => true]);
        $source = $this->snapshot();
        $source['tables']['users'][] = ['id' => 103, 'name' => 'Second'];
        $source['tables']['leave_approvers'][] = ['user_id' => 103];
        $source['tables']['leaves'][0]['approval_route'] = null;
        $source['tables']['leave_workflow_steps'] = [];
        $import = app(CrmMigrationRehearsal::class);
        $import->apply($source, $import->plan($source)['hash']);
        $entry = PersonnelEntry::where('kind', 'leave')->firstOrFail();
        $access = \Mockery::mock(PersonnelAccess::class)->makePartial();
        $access->shouldReceive('canReview')->andReturn(true);
        $this->assertFalse($access->decide($outsider, $entry));
        $this->assertTrue($access->decide($second, $entry));
        $workflow = new PersonnelWorkflow(app(OrganizationHierarchy::class), $access);
        $workflow->decide($entry, $second, 'approve', 1, null);
        $this->assertSame('approved', $entry->fresh()->status);
        $this->assertSame(1, $entry->steps()->where('status', 'approved')->count());
        $this->assertSame(1, $entry->steps()->where('status', 'cancelled')->count());
    }

    public function test_staged_pending_keeps_completed_step_and_continues_at_its_current_reviewer(): void
    {
        [$staff,$manager] = $this->people();
        $source = $this->snapshot();
        $source['tables']['leave_workflow_steps'][0]['position'] = 2;
        $source['tables']['leave_workflow_steps'][] = ['id' => 502, 'leave_id' => 401, 'position' => 1, 'stage' => 'substitute', 'approver_id' => 101, 'status' => 'approved', 'decided_at' => '2026-01-01 09:00:00'];
        $import = app(CrmMigrationRehearsal::class);
        $import->apply($source, $import->plan($source)['hash']);
        $entry = PersonnelEntry::where('kind', 'leave')->firstOrFail();
        $this->assertSame(['approved', 'pending'], $entry->steps()->pluck('status')->all());
        $this->assertSame('2026-01-01 09:00:00', $entry->steps()->first()->decided_at->format('Y-m-d H:i:s'));
        $access = \Mockery::mock(PersonnelAccess::class)->makePartial();
        $access->shouldReceive('canReview')->andReturn(true);
        $workflow = new PersonnelWorkflow(app(OrganizationHierarchy::class), $access);
        $workflow->decide($entry, $manager, 'approve', 1, null);
        $this->assertSame('approved', $entry->fresh()->status);
        $this->assertSame(2, $entry->steps()->where('status', 'approved')->count());
    }

    public function test_hourly_datetime_values_and_crm_global_report_reviewers_are_preserved(): void
    {
        [$staff,$manager] = $this->people();
        $source = $this->snapshot();
        $source['manager_chains'][101] = [];
        $source['capabilities'] = [['id' => 102, 'reports_global' => true]];
        $source['tables']['leaves'][0] = array_replace($source['tables']['leaves'][0], ['leave_unit' => 'ساعتی', 'start_date' => '2026-01-01 00:00:00', 'end_date' => '2026-01-01 00:00:00', 'start_time' => '08:15:00', 'end_time' => '10:00:00']);
        $import = app(CrmMigrationRehearsal::class);
        $import->apply($source, $import->plan($source)['hash']);
        $this->assertSame(105, PersonnelEntry::where('kind', 'leave')->first()->payload['minutes']);
        $this->assertSame([$manager->id],PersonnelEntry::where('kind','report')->first()->steps()->pluck('approver_id')->all());
        $source['flags']['report_review'] = true;
        $this->assertContains('unsupported_staged_report_review',$import->plan($source)['errors']);
    }
}
