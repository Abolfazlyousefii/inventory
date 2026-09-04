<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\Customer;
use App\Models\PreinvoiceDraftReservation;
use App\Models\PreinvoiceOrder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Services\ReservationQueryService;
use App\Services\WarehouseStockService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ReservationFilteringTest extends TestCase
{
    use RefreshDatabase;

    public function test_classification_filtering(): void
    {
        $fixture = $this->inventoryFixture();
        $temporary = $this->reservation($fixture, 3, old: false, scope: PreinvoiceDraftReservation::SCOPE_TEMPORARY_ONLINE);
        $order = $this->order(PreinvoiceOrder::STATUS_PENDING_FINANCE, old: false);
        $official = $this->reservation($fixture, 2, $order, old: false, scope: PreinvoiceDraftReservation::SCOPE_OFFICIAL);

        $manager = $this->reservationManager();

        $this->actingAs($manager)
            ->getJson('/warehouse-reservations?classification=official_preinvoice')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $official->id);

        $this->actingAs($manager)
            ->getJson('/warehouse-reservations?classification=temporary_active')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $temporary->id);
    }

    public function test_lifecycle_filtering(): void
    {
        $fixture = $this->inventoryFixture();
        $active = $this->reservation($fixture, 2, old: false, scope: PreinvoiceDraftReservation::SCOPE_TEMPORARY_ONLINE);
        $order = $this->order(PreinvoiceOrder::STATUS_PENDING_FINANCE, old: false);
        $consumedReservation = $this->reservation($fixture, 1, $order, old: false, scope: PreinvoiceDraftReservation::SCOPE_OFFICIAL);
        $consumedReservation->forceFill(['converted_at' => now()])->save();

        $manager = $this->reservationManager();

        $this->actingAs($manager)
            ->getJson('/warehouse-reservations?lifecycle=active')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $active->id);

        $this->actingAs($manager)
            ->getJson('/warehouse-reservations?lifecycle=consumed')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $consumedReservation->id);
    }

    public function test_age_filtering(): void
    {
        $fixture = $this->inventoryFixture();
        $fresh = $this->reservation($fixture, 2, old: false, scope: PreinvoiceDraftReservation::SCOPE_TEMPORARY_ONLINE);
        $order = $this->order(PreinvoiceOrder::STATUS_PENDING_FINANCE, old: false);
        $old = $this->reservation($fixture, 3, $order, old: false, scope: PreinvoiceDraftReservation::SCOPE_OFFICIAL);
        $this->setCreatedAt($old, now()->subHours(96));

        $manager = $this->reservationManager();

        $response = $this->actingAs($manager)->getJson('/warehouse-reservations?age=72h')->assertOk();
        $response->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $old->id);

        $response = $this->actingAs($manager)->getJson('/warehouse-reservations?age=24h')->assertOk();
        $response->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $old->id);

        $this->assertNotSame($fresh->id, $response->json('data.0.id'));
    }

    public function test_creator_filtering(): void
    {
        $fixture = $this->inventoryFixture();
        $creatorA = User::factory()->create();
        $creatorB = User::factory()->create();
        $reservationA = $this->reservation($fixture, 2, old: false, scope: PreinvoiceDraftReservation::SCOPE_TEMPORARY_ONLINE, userId: $creatorA->id);
        $this->reservation($fixture, 3, old: false, scope: PreinvoiceDraftReservation::SCOPE_TEMPORARY_IN_PERSON, userId: $creatorB->id);

        $manager = $this->reservationManager();

        $this->actingAs($manager)
            ->getJson("/warehouse-reservations?user_id={$creatorA->id}")
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $reservationA->id);
    }

    public function test_customer_filtering(): void
    {
        $fixture = $this->inventoryFixture();
        $temporary = $this->reservation($fixture, 2, old: false, scope: PreinvoiceDraftReservation::SCOPE_TEMPORARY_ONLINE);
        $order = $this->order(PreinvoiceOrder::STATUS_PENDING_FINANCE, old: false, customerName: 'Filtering Customer', customerMobile: '09121234567');
        $official = $this->reservation($fixture, 1, $order, old: false, scope: PreinvoiceDraftReservation::SCOPE_OFFICIAL);

        $manager = $this->reservationManager();

        $byId = $this->actingAs($manager)
            ->getJson("/warehouse-reservations?customer_id={$order->customer_id}")
            ->assertOk();
        $byId->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $official->id);

        $bySearch = $this->actingAs($manager)
            ->getJson('/warehouse-reservations?customer_search=Filtering')
            ->assertOk();
        $bySearch->assertJsonPath('total', 1)->assertJsonPath('data.0.id', $official->id);

        // Temporary reservations have no customer and must never match.
        $ids = collect($bySearch->json('data'))->pluck('id');
        $this->assertFalse($ids->contains($temporary->id));
    }

    public function test_product_filtering(): void
    {
        $fixtureA = $this->inventoryFixture();
        $fixtureB = $this->inventoryFixture();
        $reservationA = $this->reservation($fixtureA, 2, old: false, scope: PreinvoiceDraftReservation::SCOPE_TEMPORARY_ONLINE);
        $this->reservation($fixtureB, 3, old: false, scope: PreinvoiceDraftReservation::SCOPE_TEMPORARY_ONLINE);

        $manager = $this->reservationManager();

        $this->actingAs($manager)
            ->getJson("/warehouse-reservations?product_id={$fixtureA['product']->id}")
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $reservationA->id);
    }

    public function test_variant_filtering(): void
    {
        $fixtureA = $this->inventoryFixture();
        $fixtureB = $this->inventoryFixture();
        $reservationA = $this->reservation($fixtureA, 2, old: false, scope: PreinvoiceDraftReservation::SCOPE_TEMPORARY_ONLINE);
        $this->reservation($fixtureB, 3, old: false, scope: PreinvoiceDraftReservation::SCOPE_TEMPORARY_ONLINE);

        $manager = $this->reservationManager();

        $this->actingAs($manager)
            ->getJson("/warehouse-reservations?variant_id={$fixtureA['variant']->id}")
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $reservationA->id);
    }

    public function test_combined_filters(): void
    {
        $fixture = $this->inventoryFixture();
        $order = $this->order(PreinvoiceOrder::STATUS_PENDING_FINANCE, old: false);
        $match = $this->reservation($fixture, 2, $order, old: false, scope: PreinvoiceDraftReservation::SCOPE_OFFICIAL);
        // Same classification, different product — must be excluded once product_id is added.
        $otherFixture = $this->inventoryFixture();
        $otherOrder = $this->order(PreinvoiceOrder::STATUS_PENDING_FINANCE, old: false);
        $this->reservation($otherFixture, 5, $otherOrder, old: false, scope: PreinvoiceDraftReservation::SCOPE_OFFICIAL);

        $manager = $this->reservationManager();

        $this->actingAs($manager)
            ->getJson("/warehouse-reservations?classification=official_preinvoice&product_id={$fixture['product']->id}")
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $match->id);
    }

    public function test_search_improvements(): void
    {
        $fixture = $this->inventoryFixture(['variant_name' => 'Special Variant Name']);
        $byVariantName = $this->reservation($fixture, 2, old: false, scope: PreinvoiceDraftReservation::SCOPE_TEMPORARY_ONLINE);

        $customerFixture = $this->inventoryFixture();
        $order = $this->order(PreinvoiceOrder::STATUS_PENDING_FINANCE, old: false, customerName: 'Searchable Customer Name');
        $byCustomerName = $this->reservation($customerFixture, 1, $order, old: false, scope: PreinvoiceDraftReservation::SCOPE_OFFICIAL);

        $manager = $this->reservationManager();

        $this->actingAs($manager)
            ->getJson('/warehouse-reservations?search=Special Variant')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $byVariantName->id);

        $this->actingAs($manager)
            ->getJson('/warehouse-reservations?search=Searchable Customer')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('data.0.id', $byCustomerName->id);
    }

    public function test_pagination_correctness(): void
    {
        $fixture = $this->inventoryFixture();
        $order = $this->order(PreinvoiceOrder::STATUS_PENDING_FINANCE, old: false);
        for ($i = 0; $i < 3; $i++) {
            $this->reservation($fixture, 1, $order, old: false, scope: PreinvoiceDraftReservation::SCOPE_OFFICIAL);
        }
        // Non-matching classification noise.
        $this->reservation($fixture, 1, old: false, scope: PreinvoiceDraftReservation::SCOPE_TEMPORARY_ONLINE);

        $manager = $this->reservationManager();

        $response = $this->actingAs($manager)
            ->getJson('/warehouse-reservations?classification=official_preinvoice')
            ->assertOk();

        $this->assertSame(3, $response->json('total'));
        $this->assertCount(3, $response->json('data'));
    }

    public function test_html_and_json_filtering_consistency(): void
    {
        $fixture = $this->inventoryFixture();
        $order = $this->order(PreinvoiceOrder::STATUS_PENDING_FINANCE, old: false);
        $match = $this->reservation($fixture, 2, $order, old: false, scope: PreinvoiceDraftReservation::SCOPE_OFFICIAL);
        $this->reservation($fixture, 1, old: false, scope: PreinvoiceDraftReservation::SCOPE_TEMPORARY_ONLINE);

        $manager = $this->reservationManager();

        $json = $this->actingAs($manager)
            ->getJson('/warehouse-reservations?classification=official_preinvoice')
            ->assertOk();
        $this->assertSame([$match->id], collect($json->json('data'))->pluck('id')->all());

        $html = $this->actingAs($manager)
            ->get('/warehouse-reservations?classification=official_preinvoice')
            ->assertOk();
        $html->assertViewHas('reservations', function ($reservations) use ($match) {
            return $reservations->pluck('id')->all() === [$match->id];
        });
    }

    public function test_no_stock_movement_happens_from_filtering(): void
    {
        $fixture = $this->inventoryFixture();
        $this->reservation($fixture, 3, old: false, scope: PreinvoiceDraftReservation::SCOPE_TEMPORARY_ONLINE);

        $movementCount = DB::table('stock_movements')->count();
        $warehouseQuantity = $fixture['warehouseStock']->quantity;

        $manager = $this->reservationManager();
        $this->actingAs($manager)->getJson('/warehouse-reservations?classification=temporary_active&age=24h&product_id='.$fixture['product']->id)->assertOk();

        $service = app(ReservationQueryService::class);
        $service->filteredManagementQuery(['classification' => 'temporary_active'])->paginate(20);

        $this->assertSame($movementCount, DB::table('stock_movements')->count());
        $this->assertSame($warehouseQuantity, $fixture['warehouseStock']->fresh()->quantity);
    }

    private function reservationManager(array $permissions = ['warehouse_reservations.view']): User
    {
        $role = Role::findOrCreate('reservation-filtering-'.Str::random(8), 'web');

        foreach ($permissions as $key) {
            $role->givePermissionTo(Permission::query()->where('key', $key)->firstOrFail());
        }

        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function inventoryFixture(array $variantOverrides = []): array
    {
        $category = Category::withoutEvents(fn () => Category::query()->create(['name' => 'Filtering '.Str::uuid()]));
        $product = Product::withoutEvents(fn () => Product::query()->create([
            'category_id' => $category->id,
            'name' => 'Filtering product',
            'sku' => 'FILTER-'.Str::uuid(),
            'stock' => 20,
            'reserved' => 0,
            'price' => 1000,
            'is_sellable' => true,
        ]));
        $variant = ProductVariant::withoutEvents(fn () => ProductVariant::query()->create(array_merge([
            'product_id' => $product->id,
            'variant_name' => 'Filtering variant',
            'variant_code' => 'FILTER-V-'.Str::uuid(),
            'stock' => 20,
            'reserved' => 0,
            'sell_price' => 1000,
            'is_active' => true,
            'sales_enabled' => true,
        ], $variantOverrides)));
        $warehouseStock = WarehouseStock::withoutEvents(fn () => WarehouseStock::query()->create([
            'warehouse_id' => WarehouseStockService::centralWarehouseId(),
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => 20,
        ]));

        return compact('product', 'variant', 'warehouseStock');
    }

    private function reservation(
        array $fixture,
        int $quantity,
        ?PreinvoiceOrder $order = null,
        bool $old = true,
        ?string $scope = null,
        ?int $userId = null,
    ): PreinvoiceDraftReservation {
        $reservation = PreinvoiceDraftReservation::query()->create([
            'token' => (string) Str::uuid(),
            'user_id' => $userId,
            'preinvoice_order_id' => $order?->id,
            'product_id' => $fixture['product']->id,
            'variant_id' => $fixture['variant']->id,
            'quantity' => $quantity,
            'reservation_scope' => $scope,
            'last_seen_at' => $old ? now()->subDays(10) : now(),
            'expires_at' => $old ? now()->subDays(10) : now()->addMinutes(10),
        ]);
        $timestamp = $old ? now()->subDays(4) : now();
        DB::table('preinvoice_draft_reservations')->where('id', $reservation->id)->update([
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return $reservation->refresh();
    }

    private function setCreatedAt(PreinvoiceDraftReservation $reservation, \DateTimeInterface $at): void
    {
        DB::table('preinvoice_draft_reservations')->where('id', $reservation->id)->update([
            'created_at' => $at,
        ]);
        $reservation->refresh();
    }

    private function order(
        string $status,
        bool $old,
        ?string $customerName = null,
        ?string $customerMobile = null,
    ): PreinvoiceOrder {
        $user = User::factory()->create();
        $order = PreinvoiceOrder::withoutEvents(fn () => PreinvoiceOrder::query()->create([
            'uuid' => (string) Str::uuid(),
            'created_by' => $user->id,
            'seller_id' => $user->id,
            'document_date' => now(),
            'status' => $status,
            'customer_id' => Customer::factory()->create()->id,
            'customer_name' => $customerName ?? 'Filtering customer',
            'customer_mobile' => $customerMobile ?? '09120000000',
            'total_price' => 1000,
        ]));
        if ($old) {
            DB::table('preinvoice_orders')->where('id', $order->id)->update([
                'created_at' => now()->subDays(4),
                'updated_at' => now()->subDays(4),
            ]);
        }

        return $order->refresh();
    }
}
