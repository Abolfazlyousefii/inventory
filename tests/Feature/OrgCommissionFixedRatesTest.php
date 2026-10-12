<?php

namespace Tests\Feature;

use App\Models\OrgCommissionDepartment;
use App\Models\OrgCommissionDocument;
use App\Models\User;
use App\Services\Finance\OrgCommissionService;
use App\Services\Finance\OrgCommissionSettingsService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class OrgCommissionFixedRatesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->string('name');
            $t->timestamps();
        });
        DB::table('users')->insert(['id' => 1, 'name' => 'مالی']);
        (require database_path('migrations/2026_09_14_000001_create_organizational_commission_tables.php'))->up();
        Schema::create('activity_logs', function (Blueprint $t) {
            $t->id();
            foreach (['user_id', 'action', 'subject_type', 'subject_id', 'description', 'properties', 'occurred_at'] as $f) {
                $t->text($f)->nullable();
            } $t->timestamps();
        });
        Schema::create('seller_sales_documents', function (Blueprint $t) {
            $t->id();
            $t->string('status');
            $t->date('period_from');
            $t->date('period_to');
            $t->bigInteger('net_commission_amount');
            $t->timestamps();
        });
        $migration = database_path('migrations/2026_10_12_200000_add_org_commission_fixed_rates.php');
        if (file_exists($migration)) {
            (require $migration)->up();
        }
        DB::table('seller_sales_documents')->insert(['status' => 'confirmed', 'period_from' => '2026-09-01', 'period_to' => '2026-09-30', 'net_commission_amount' => 1000000]);
    }

    private function fixture(): array
    {
        $dept = OrgCommissionDepartment::create(['name' => 'مدیران', 'created_by' => 1]);
        $a = $dept->members()->create(['name' => 'مدیر اول', 'percentage' => '7.0000']);
        $b = $dept->members()->create(['name' => 'مدیر دوم', 'percentage' => '5.0000']);

        return [$dept, $a, $b];
    }

    private function payload(): array
    {
        return ['period_from' => '2026-09-01', 'period_to' => '2026-09-30', 'settings_version' => app(OrgCommissionSettingsService::class)->snapshot(false, false)['version']];
    }

    public function test_uses_person_rates_and_ignores_posted_manual_allocations(): void
    {
        [$dept] = $this->fixture();
        $doc = app(OrgCommissionService::class)->createDocument($this->payload() + ['allocations' => [['department_id' => $dept->id, 'percentage' => 99]]], User::find(1));
        $this->assertSame(120000, $doc->total_allocated);
        $this->assertSame([70000, 50000], $doc->allocations->first()->memberShares->pluck('share_amount')->all());
        $this->assertSame('12.0000', $doc->allocations->first()->percentage);
    }

    public function test_existing_draft_keeps_snapshot_when_settings_change(): void
    {
        [, $a] = $this->fixture();
        $svc = app(OrgCommissionService::class);
        $doc = $svc->createDocument($this->payload(), User::find(1));
        $a->update(['percentage' => '10.0000']);
        $updated = $svc->updateDocument($doc, ['period_from' => '2026-09-01', 'period_to' => '2026-09-30', 'allocations' => []], User::find(1));
        $this->assertSame(120000, $updated->total_allocated);
        $new = $svc->createDocument($this->payload(), User::find(1));
        $this->assertSame(150000, $new->total_allocated);
        $this->assertSame($doc->commission_settings_snapshot, $updated->commission_settings_snapshot);
    }

    public function test_stale_settings_are_rejected_before_document_creation(): void
    {
        [, $a] = $this->fixture();
        $payload = $this->payload();
        $a->update(['percentage' => '9']);
        try {
            app(OrgCommissionService::class)->createDocument($payload, User::find(1));
            $this->fail();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('settings_version', $e->errors());
        }
        $this->assertSame(0, OrgCommissionDocument::count());
    }

    public function test_missing_rate_cannot_silently_omit_a_member(): void
    {
        [$dept] = $this->fixture();
        $dept->members()->create(['name' => 'عضو جدید']);
        $this->expectException(ValidationException::class);
        app(OrgCommissionService::class)->createDocument($this->payload(), User::find(1));
    }

    public function test_settings_over_one_hundred_are_atomic_and_audited(): void
    {
        [, $a, $b] = $this->fixture();
        $svc = app(OrgCommissionSettingsService::class);
        $version = $svc->snapshot(false, false)['version'];
        try {
            $svc->saveRates([$a->id => '90', $b->id => '20'], $version, 1);
            $this->fail();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('rates', $e->errors());
        }
        $this->assertSame('7.0000', $a->fresh()->percentage);
        $svc->saveRates([$a->id => '8.5', $b->id => '5'], $version, 1);
        $this->assertSame('8.5000', $a->fresh()->percentage);
        $this->assertSame(1, DB::table('activity_logs')->where('action', 'org_commission_settings.updated')->count());
    }

    public function test_seed_preserves_old_document_and_uses_amount_ratios(): void
    {
        [$dept,$a,$b] = $this->fixture();
        $doc = OrgCommissionDocument::create(['uuid' => 'old', 'document_number' => 'OC-OLD', 'period_from' => '2026-09-01', 'period_to' => '2026-09-30', 'total_seller_commission' => 1000000, 'total_allocated' => 170000, 'status' => 'confirmed', 'created_by' => 1]);
        $alloc = $doc->allocations()->create(['department_id' => $dept->id, 'percentage' => 17, 'allocated_amount' => 170000]);
        $alloc->memberShares()->create(['member_id' => $a->id, 'share_amount' => 70000]);
        $alloc->memberShares()->create(['member_id' => $b->id, 'share_amount' => 50000]);
        $a->update(['percentage' => null]);
        $b->update(['percentage' => null]);
        $before = $doc->fresh()->getAttributes();
        app(OrgCommissionSettingsService::class)->initializeFromDocument($doc, 1);
        $this->assertSame('7.0000', $a->fresh()->percentage);
        $this->assertSame('5.0000', $b->fresh()->percentage);
        $this->assertSame($before, $doc->fresh()->getAttributes());
        $this->assertSame(170000, $alloc->fresh()->allocated_amount);
    }

    public function test_inactive_members_are_excluded_and_rounding_reconciles(): void
    {
        [$dept,$a,$b] = $this->fixture();
        $b->update(['is_active' => false]);
        $a->update(['percentage' => '4.1234']);
        $doc = app(OrgCommissionService::class)->createDocument($this->payload(), User::find(1));
        $this->assertSame(41234, $doc->total_allocated);
        $this->assertSame($doc->total_allocated, (int) $doc->allocations->sum('allocated_amount'));
        $this->assertSame($doc->total_allocated, (int) $doc->allocations->first()->memberShares->sum('share_amount'));
    }

    public function test_settings_precision_missing_people_and_old_page_are_rejected(): void
    {
        [, $a, $b] = $this->fixture();
        $svc = app(OrgCommissionSettingsService::class);
        $version = $svc->snapshot(false, false)['version'];
        foreach ([[$a->id => '5'], [$a->id => '5.00001', $b->id => '5'], [$a->id => '-1', $b->id => '5']] as $rates) {
            try {
                $svc->saveRates($rates, $version, 1);
                $this->fail('Unsafe rates accepted');
            } catch (ValidationException $e) {
                $this->assertArrayHasKey('rates', $e->errors());
            }
        }
        $svc->saveRates([$a->id => '۷٫۵', $b->id => '5'], $version, 1);
        $this->assertSame('7.5000', $a->fresh()->percentage);
        try {
            $svc->saveRates([$a->id => '8', $b->id => '5'], $version, 1);
            $this->fail();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('settings_version', $e->errors());
        }
    }

    public function test_confirmation_stops_recalculation_and_copies_names(): void
    {
        [$dept,$a] = $this->fixture();
        $svc = app(OrgCommissionService::class);
        $doc = $svc->createDocument($this->payload(), User::find(1));
        $before = $doc->fresh()->getAttributes();
        $a->update(['name' => 'نام جدید', 'percentage' => '20']);
        $dept->update(['name' => 'واحد جدید']);
        $this->assertSame('مدیر اول', $doc->commission_settings_snapshot['departments'][0]['members'][0]['name']);
        $this->assertSame('مدیران', $doc->commission_settings_snapshot['departments'][0]['name']);
        $svc->confirmDocument($doc, 1);
        $doc->refresh();
        try {
            $svc->updateDocument($doc, ['period_from' => '2026-10-01', 'period_to' => '2026-10-31'], User::find(1));
            $this->fail();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('document', $e->errors());
        }
        $this->assertSame(120000, $doc->fresh()->total_allocated);
    }

    public function test_negative_base_rejects_without_creating_a_document(): void
    {
        $this->fixture();
        DB::table('seller_sales_documents')->update(['net_commission_amount' => -100]);
        try {
            app(OrgCommissionService::class)->createDocument($this->payload(), User::find(1));
            $this->fail();
        } catch (ValidationException $e) {
            $this->assertArrayHasKey('period_from', $e->errors());
        }
        $this->assertSame(0, OrgCommissionDocument::count());
    }

    public function test_new_member_does_not_change_an_existing_document(): void
    {
        [$dept] = $this->fixture();
        $svc = app(OrgCommissionService::class);
        $doc = $svc->createDocument($this->payload(), User::find(1));
        $dept->members()->create(['name' => 'عضو اضافه', 'percentage' => '10']);
        $same = $svc->updateDocument($doc, ['period_from' => '2026-09-01', 'period_to' => '2026-09-30'], User::find(1));
        $this->assertCount(2,$same->allocations->first()->memberShares);
        $this->assertSame(120000,$same->total_allocated);
    }

    public function test_document_form_is_read_only_for_rates_and_has_settings_link(): void
    {
        $this->fixture();
        Schema::create('categories', function (Blueprint $t) { $t->id(); $t->string('name'); });
        view()->share('errors', new \Illuminate\Support\ViewErrorBag);
        $html = app(\App\Http\Controllers\OrgCommissionController::class)->create()->render();
        $this->assertStringContainsString('settings_version', $html);
        $this->assertStringContainsString('محاسبه سهم‌ها', $html);
        $this->assertStringNotContainsString('name="allocations[', $html);
        $this->assertStringNotContainsString('split-equally', $html);
        $settings = app(\App\Http\Controllers\OrgCommissionController::class)->settings()->render();
        $this->assertStringContainsString('name="rates[', $settings);
        if ($directory = getenv('ORG_COMMISSION_PREVIEW_DIR')) {
            file_put_contents($directory.'/form-local.html', $html);
            file_put_contents($directory.'/settings-local.html', $settings);
        }
    }

    public function test_settings_routes_use_the_existing_financial_page_permission(): void
    {
        $expected=\App\Support\PageAccessCatalog::permissionsForRoute('finance.org-commission.index');
        $this->assertNotEmpty($expected);
        $this->assertSame($expected,\App\Support\PageAccessCatalog::permissionsForRoute('finance.org-commission.settings'));
        $this->assertSame($expected,\App\Support\PageAccessCatalog::permissionsForRoute('finance.org-commission.settings.update'));
    }
}
