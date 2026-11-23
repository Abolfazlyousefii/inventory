<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\User;
use App\Services\CrmProductionImport;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CrmProductionImportTest extends TestCase
{
    use RefreshDatabase;

    private function source(): array
    {
        User::factory()->create(['crm_user_id'=>'101']);
        return ['schema_version'=>1,'source'=>'ariya-crm','flags'=>['report_review'=>false],
            'tables'=>['users'=>[['id'=>101]],'customers'=>[
                ['id'=>201,'name'=>'CRM shop name','phone'=>'09121112233','user_id'=>101],
                ['id'=>202,'name'=>'Quarantined identity','phone'=>'09124445566','user_id'=>101],
            ],'customer_notes'=>[['id'=>301,'customer_id'=>202,'user_id'=>101,'title'=>'Old contact','content'=>'Keep source history','created_at'=>'2026-01-01 10:00:00','updated_at'=>'2026-01-01 10:00:00']]],
            'erp_customer_mapping'=>['201'=>null,'202'=>null],'erp_review_customers'=>[202]];
    }

    public function test_approved_phone_link_keeps_financial_identity_and_conflicts_stay_unassigned(): void
    {
        $s=$this->source();
        $financial=Customer::factory()->create(['mobile'=>'09121112233','name'=>'ERP company name','opening_balance'=>987654]);
        $conflict=Customer::factory()->create(['crm_customer_id'=>'202','mobile'=>'09999999999','opening_balance'=>123456]);
        $s['erp_customer_mapping']['201']=$financial->id;
        $import=app(CrmProductionImport::class);$plan=$import->plan($s);
        $this->assertSame([],$plan['errors']);$import->apply($s,$plan['hash']);
        $this->assertSame(987654,$financial->fresh()->opening_balance);
        $this->assertSame('ERP company name',$financial->fresh()->name);
        $this->assertSame(123456,$conflict->fresh()->opening_balance);
        $review=Customer::where('sync_source','crm-migration-review')->firstOrFail();
        $this->assertFalse($review->is_active);$this->assertNull($review->crm_customer_id);
        $this->assertFalse($review->assignment()->exists());
        $this->assertDatabaseHas('customer_interactions',['customer_id'=>$review->id,'body'=>"Old contact\nKeep source history"]);
        $this->assertDatabaseCount('customer_ledgers',0);
        $this->assertDatabaseCount('customer_assignments',1);
        $import->apply($s,$import->plan($s)['hash']);
        $this->assertDatabaseCount('customers',3);
        $this->assertDatabaseCount('customer_interactions',1);
    }

    public function test_nonmatching_phone_and_duplicate_destination_fail_before_writing(): void
    {
        $s=$this->source();$c=Customer::factory()->create(['mobile'=>'09000000000']);
        $s['erp_customer_mapping']=['201'=>$c->id,'202'=>$c->id];
        $plan=app(CrmProductionImport::class)->plan($s);
        $this->assertContains('duplicate_target_customer_mapping',$plan['errors']);
        $this->assertContains('invalid_customer_mapping:201',$plan['errors']);
        $this->assertDatabaseCount('crm_import_records',0);
    }

    public function test_new_customer_is_created_without_financial_history_and_import_is_repeatable(): void
    {
        $s=$this->source();$import=app(CrmProductionImport::class);
        $plan=$import->plan($s);$this->assertSame([],$plan['errors']);$import->apply($s,$plan['hash']);
        $c=Customer::where('crm_customer_id','201')->firstOrFail();
        $this->assertTrue($c->is_active);$this->assertSame(0,$c->opening_balance);
        $this->assertDatabaseCount('customer_ledgers',0);
        $import->apply($s,$import->plan($s)['hash']);
        $this->assertSame(2,Customer::count());
    }
}
