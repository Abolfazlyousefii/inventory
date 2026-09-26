<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerLedger;
use App\Models\Invoice;
use App\Models\Province;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AccountStatementRegressionTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private Customer $target;

    private Customer $other;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create();
        $this->owner->assignRole(Role::findOrCreate('Owner', 'web'));

        $province = Province::query()->create(['name' => 'Tehran', 'is_active' => true]);
        $city = City::query()->create([
            'province_id' => $province->id,
            'name' => 'Shahriar',
            'is_active' => true,
        ]);

        $this->target = Customer::factory()->create([
            'crm_customer_id' => 'CRM-77881',
            'first_name' => 'Aria',
            'last_name' => 'Gostar',
            'mobile' => '09121234567',
            'province_id' => $province->id,
            'city_id' => $city->id,
        ]);

        $this->other = Customer::factory()->create([
            'crm_customer_id' => 'CRM-OTHER',
            'first_name' => 'Other',
            'last_name' => 'Customer',
            'mobile' => '09990000000',
            'province_id' => null,
            'city_id' => null,
        ]);
    }

    public function test_index_handles_customers_with_valid_and_null_cities(): void
    {
        $this->actingAs($this->owner)
            ->get(route('account-statements.index'))
            ->assertOk()
            ->assertSee('Aria Gostar')
            ->assertSee('Other Customer');
    }

    #[DataProvider('searchProvider')]
    public function test_searches_supported_customer_fields(string $term): void
    {
        $this->actingAs($this->owner)
            ->get(route('account-statements.index', ['q' => $term]))
            ->assertOk()
            ->assertSee('Aria Gostar')
            ->assertDontSee('Other Customer');
    }

    public static function searchProvider(): array
    {
        return [
            'city' => ['Shahriar'],
            'name' => ['Aria Gostar'],
            'mobile' => ['09121234567'],
            'database id' => ['1'],
            'crm id' => ['CRM-77881'],
        ];
    }

    public function test_search_matches_arabic_and_persian_yeh_and_kaf_without_changing_customer_names(): void
    {
        $this->target->update([
            'first_name' => 'علي',
            'last_name' => 'كريمي',
            'name' => null,
        ]);

        foreach (['علی', 'علي', 'کریمی', 'كريمي', 'علی کریمی'] as $term) {
            $this->actingAs($this->owner)
                ->get(route('account-statements.index', ['q' => $term]))
                ->assertOk()
                ->assertSee('علي كريمي')
                ->assertDontSee('Other Customer');
        }

        $this->assertSame('علي', $this->target->fresh()->first_name);
        $this->assertSame('كريمي', $this->target->fresh()->last_name);
    }

    public function test_search_matches_the_display_name_when_it_uses_the_name_column(): void
    {
        $this->target->update(['name' => 'علي رضايي']);

        $this->actingAs($this->owner)
            ->get(route('account-statements.index', ['q' => 'علی رضایی']))
            ->assertOk()
            ->assertSee('علي رضايي')
            ->assertDontSee('Other Customer');
    }

    public function test_financial_sorting_uses_numeric_balances_across_all_customers(): void
    {
        $this->target->update(['opening_balance' => 500]);
        $this->other->update(['opening_balance' => -200]);
        Customer::factory()->create([
            'first_name' => 'Third', 'last_name' => 'Customer',
            'name' => null, 'opening_balance' => 1200,
        ]);
        CustomerLedger::query()->create([
            'customer_id' => $this->target->id,
            'type' => 'debit',
            'amount' => 800,
        ]);

        $this->actingAs($this->owner)
            ->get(route('account-statements.index', ['sort' => 'debt', 'direction' => 'desc']))
            ->assertOk()
            ->assertSeeInOrder(['Aria Gostar', 'Third Customer', 'Other Customer']);

        $this->get(route('account-statements.index', ['sort' => 'credit', 'direction' => 'desc']))
            ->assertOk()
            ->assertSeeInOrder(['Other Customer', 'Third Customer', 'Aria Gostar']);

        $this->get(route('account-statements.index', ['sort' => 'debt', 'direction' => 'asc']))
            ->assertOk()
            ->assertSeeInOrder(['Other Customer', 'Third Customer', 'Aria Gostar']);
    }

    public function test_sorting_links_cycle_from_descending_to_ascending_to_default(): void
    {
        $default = $this->actingAs($this->owner)->get(route('account-statements.index'));
        $default->assertOk()->assertSee('sort=credit&amp;direction=desc', false);

        $descending = $this->get(route('account-statements.index', ['sort' => 'credit', 'direction' => 'desc']));
        $descending->assertOk()->assertSee('sort=credit&amp;direction=asc', false);

        $ascending = $this->get(route('account-statements.index', ['sort' => 'credit', 'direction' => 'asc']));
        $ascending->assertOk()->assertDontSee('sort=credit&amp;direction=', false);
    }

    public function test_detail_balance_matches_list_when_an_invoice_was_cancelled(): void
    {
        $cancelled = Invoice::query()->create([
            'uuid' => 'AS-CANCELLED-1',
            'customer_id' => $this->target->id,
            'customer_name' => $this->target->display_name,
            'total' => 80_000_000,
            'status' => Invoice::STATUS_NOT_SHIPPED,
        ]);
        CustomerLedger::query()->create([
            'customer_id' => $this->target->id,
            'type' => 'debit',
            'amount' => 80_000_000,
            'reference_type' => Invoice::class,
            'reference_id' => $cancelled->id,
        ]);

        $this->actingAs($this->owner)
            ->get(route('account-statements.show', $this->target))
            ->assertOk()
            ->assertSee('وضعیت نهایی حساب')
            ->assertSee('تسویه');
    }

    public function test_balance_adjustment_records_only_the_difference_with_an_audit_log(): void
    {
        $this->target->update(['opening_balance' => 500]);

        $this->actingAs($this->owner)
            ->post(route('account-statements.adjustments.store', $this->target), [
                'balance_type' => 'credit',
                'target_amount' => '۲۰۰',
                'expected_balance' => 500,
                'reason' => 'اصلاح مانده طبق تأیید واحد مالی',
            ])
            ->assertRedirect(route('account-statements.show', $this->target));

        $ledger = CustomerLedger::query()->where('customer_id', $this->target->id)->sole();
        $log = ActivityLog::query()->where('action', 'customer_balance_adjusted')->sole();
        $this->assertSame('credit', $ledger->type);
        $this->assertSame(700, (int) $ledger->amount);
        $this->assertSame(ActivityLog::class, $ledger->reference_type);
        $this->assertSame($log->id, $ledger->reference_id);
        $this->assertSame($this->owner->id, $log->user_id);
        $this->assertSame(500, $log->properties['balance_before']);
        $this->assertSame(-200, $log->properties['balance_after']);
        $this->assertSame($ledger->id, $log->properties['ledger_id']);
        $this->assertSame(500, $this->target->fresh()->opening_balance);
        $this->assertSame(-200, Customer::query()->withBalance()->findOrFail($this->target->id)->balance);
        $this->get(route('account-statements.show', $this->target))
            ->assertOk()
            ->assertSee('سابقه اصلاح مانده')
            ->assertSee('اصلاح مانده طبق تأیید واحد مالی')
            ->assertSee('بستانکار');
    }

    public function test_balance_adjustment_rejects_a_stale_balance_without_writing(): void
    {
        $this->target->update(['opening_balance' => 500]);

        $this->actingAs($this->owner)
            ->from(route('account-statements.show', $this->target))
            ->post(route('account-statements.adjustments.store', $this->target), [
                'balance_type' => 'settled',
                'target_amount' => '0',
                'expected_balance' => 400,
                'reason' => 'اصلاح مانده طبق تأیید واحد مالی',
            ])
            ->assertSessionHasErrors('target_amount');

        $this->assertSame(0, CustomerLedger::query()->where('customer_id', $this->target->id)->count());
        $this->assertSame(0, ActivityLog::query()->where('action', 'customer_balance_adjusted')->count());
    }

    public function test_balance_adjustment_rejects_noop_and_missing_reason(): void
    {
        $this->actingAs($this->owner)
            ->post(route('account-statements.adjustments.store', $this->target), [
                'balance_type' => 'settled',
                'target_amount' => '0',
                'expected_balance' => 0,
                'reason' => 'اصلاح مانده طبق تأیید واحد مالی',
            ])
            ->assertSessionHasErrors('target_amount');

        $this->post(route('account-statements.adjustments.store', $this->target), [
            'balance_type' => 'debit',
            'target_amount' => '100',
            'expected_balance' => 0,
            'reason' => '',
        ])->assertSessionHasErrors('reason');

        $this->assertSame(0, CustomerLedger::query()->where('customer_id', $this->target->id)->count());
    }
}
