<?php

namespace Tests\Feature;

use App\Models\City;
use App\Models\Customer;
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
}
