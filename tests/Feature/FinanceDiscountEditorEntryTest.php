<?php

namespace Tests\Feature;

use App\Models\PreinvoiceOrder;
use App\Models\User;
use App\Services\FinancePreinvoiceEditorService;
use App\Services\PreinvoiceReservationService;
use App\Services\ProductDiscountAllocator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Mockery;
use Tests\TestCase;

class FinanceDiscountEditorEntryTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('preinvoice_orders', function (Blueprint $t) {
            $t->id();
        });
        Schema::create('preinvoice_order_items', function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('preinvoice_order_id');
            $t->integer('quantity');
            $t->bigInteger('price');
        });
        DB::table('preinvoice_orders')->insert(['id' => 1]);
        DB::table('preinvoice_order_items')->insert(['id' => 2, 'preinvoice_order_id' => 1, 'quantity' => 41218, 'price' => 1180000]);
    }

    private function editor()
    {
        return Mockery::mock(FinancePreinvoiceEditorService::class, [app(PreinvoiceReservationService::class), app(ProductDiscountAllocator::class)])->makePartial();
    }

    public function test_discount_only_editor_enters_transaction_and_delegates_unchanged_items(): void
    {
        $order = PreinvoiceOrder::findOrFail(1);
        $actor = new User;
        $data = ['items' => [['id' => 2, 'quantity' => 41218, 'price' => 1180000]], 'product_discounts' => [['product_id' => 1514, 'type' => 'amount', 'value' => 40393640000]]];
        $editor = $this->editor();
        $editor->shouldReceive('update')->once()->withArgs(fn ($locked, $payload, $user) => $locked->id === 1 && $payload === $data && $user === $actor)->andReturn($order);
        $this->assertSame($order, $editor->updateDiscounts($order, $data, $actor));
        $this->assertSame(0, DB::transactionLevel());
    }

    public function test_discount_only_editor_rejects_quantity_changes_without_writes(): void
    {
        $editor = $this->editor();
        $editor->shouldNotReceive('update');
        try {
            $editor->updateDiscounts(PreinvoiceOrder::findOrFail(1), ['items' => [['id' => 2, 'quantity' => 1, 'price' => 1180000]]], new User);
            $this->fail('Quantity change must be rejected.');
        } catch (ValidationException $error) {
            $this->assertArrayHasKey('items', $error->errors());
        }
        $this->assertSame(41218, DB::table('preinvoice_order_items')->value('quantity'));
        $this->assertSame(0, DB::transactionLevel());
    }
}
