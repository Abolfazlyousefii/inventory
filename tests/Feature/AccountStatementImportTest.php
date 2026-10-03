<?php

namespace Tests\Feature;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AccountStatementImportTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;
    private Customer $customer;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->owner = User::factory()->create();
        $this->owner->assignRole(Role::findOrCreate('Owner', 'web'));
        $this->customer = Customer::factory()->create([
            'name' => 'علی کریمی', 'first_name' => 'علی', 'last_name' => 'کریمی',
            'mobile' => '09120000000', 'opening_balance' => 500,
        ]);
    }

    public function test_import_previews_and_posts_only_the_difference_with_audit_log(): void
    {
        $this->actingAs($this->owner);
        $this->get(route('account-statements.import.create'))->assertOk();
        $this->post(route('account-statements.import.preview'), ['file' => $this->file($this->row(-200))])
            ->assertOk()->assertSee('پیش‌نمایش اصلاح مانده');
        $this->assertDatabaseCount('customer_ledgers', 0);
        $token = array_key_first(session('account_statement_imports'));
        $this->postJson(route('account-statements.import.apply'), ['token' => $token, 'offset' => 0, 'confirmed' => true])
            ->assertOk()->assertJsonPath('applied', 1)->assertJsonPath('done', true);
        $ledger = CustomerLedger::query()->sole();
        $log = ActivityLog::query()->where('action', 'customer_balance_adjusted')->sole();
        $this->assertSame('credit', $ledger->type);
        $this->assertSame(700, (int) $ledger->amount);
        $this->assertSame($log->id, $ledger->reference_id);
        $this->assertSame('account_statement_excel_import', $log->properties['source']);
        $this->assertSame(500, $this->customer->fresh()->opening_balance);
        $this->assertSame(-200, Customer::query()->withBalance()->find($this->customer->id)->balance);
    }

    public function test_changed_balance_after_preview_is_skipped(): void
    {
        $this->actingAs($this->owner)
            ->post(route('account-statements.import.preview'), ['file' => $this->file($this->row(-200))])
            ->assertOk();
        $token = array_key_first(session('account_statement_imports'));
        CustomerLedger::query()->create(['customer_id' => $this->customer->id, 'type' => 'credit', 'amount' => 100]);
        $this->postJson(route('account-statements.import.apply'), ['token' => $token, 'offset' => 0, 'confirmed' => true])
            ->assertOk()->assertJsonPath('applied', 0)->assertJsonCount(1, 'errors');
        $this->assertDatabaseCount('customer_ledgers', 1);
        $this->assertSame(0, ActivityLog::query()->where('action', 'customer_balance_adjusted')->count());
    }

    public function test_mismatched_identity_and_invalid_amount_cannot_be_applied(): void
    {
        $row = $this->row(-200);
        $row[2] = '09999999999';
        $this->actingAs($this->owner)
            ->post(route('account-statements.import.preview'), ['file' => $this->file($row)])
            ->assertOk()->assertSee('نام یا موبایل');
        $token = array_key_first(session('account_statement_imports'));
        $this->postJson(route('account-statements.import.apply'), ['token' => $token, 'offset' => 0, 'confirmed' => true])
            ->assertOk()->assertJsonPath('total', 0);
        $this->assertDatabaseCount('customer_ledgers', 0);
    }

    public function test_uploading_the_same_file_again_does_not_create_another_ledger(): void
    {
        $this->actingAs($this->owner)
            ->post(route('account-statements.import.preview'), ['file' => $this->file($this->row(-200))])
            ->assertOk();
        $token = array_key_first(session('account_statement_imports'));
        $this->postJson(route('account-statements.import.apply'), ['token' => $token, 'offset' => 0, 'confirmed' => true])
            ->assertOk()->assertJsonPath('applied', 1);
        $this->post(route('account-statements.import.preview'), ['file' => $this->file($this->row(-200))])
            ->assertOk()->assertSee('بدون تغییر');
        $secondToken = array_key_first(session('account_statement_imports'));
        $this->postJson(route('account-statements.import.apply'), ['token' => $secondToken, 'offset' => 0, 'confirmed' => true])
            ->assertOk()->assertJsonPath('total', 0);
        $this->assertDatabaseCount('customer_ledgers', 1);
    }

    private function row(int $target): array
    {
        return [$this->customer->id, 'علي كريمي', '09120000000', 500, $target, '123', 'تطبیق مانده با خروجی سازه حساب و تایید مالی'];
    }

    private function file(array $row): UploadedFile
    {
        $book = new Spreadsheet();
        $sheet = $book->getActiveSheet();
        $sheet->fromArray([
            ['شناسه مشتری', 'نام مشتری', 'موبایل', 'مانده فعلی نرم‌افزار (ریال)', 'مانده هدف سازه حساب (ریال)', 'کد سازه حساب', 'دلیل اصلاح'],
            $row,
        ]);
        $path = tempnam(sys_get_temp_dir(), 'balance-import-').'.xlsx';
        (new Xlsx($book))->save($path);
        $book->disconnectWorksheets();
        return new UploadedFile($path, 'balance-import.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }
}
