<?php

namespace Tests\Feature;

use App\Http\Requests\ReceiveWarehouseInboundReceiptRequest;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class WarehouseInboundJsonTransportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('warehouses', function (Blueprint $t) {
            $t->id();
            $t->boolean('is_active');
            $t->string('type');
        });
        DB::table('warehouses')->insert(['id' => 1, 'is_active' => true, 'type' => 'central']);
        Route::post('/test/inbound-transport', fn (ReceiveWarehouseInboundReceiptRequest $r) => response()->json(['items' => $r->validated('items'), 'version' => $r->validated('receipt_version'), 'note' => $r->validated('review_note')]));
    }

    private function rows(int $count): array
    {
        return array_map(fn ($n) => ['id' => (string) $n, 'accepted_quantity' => (string) ($n % 3), 'received_warehouse_id' => '1', 'note' => 'قلم '.$n], range(1, $count));
    }

    private function payload(array $rows): array
    {
        return ['receipt_version' => str_repeat('a', 64), 'items_payload' => json_encode($rows, JSON_UNESCAPED_UNICODE), 'items_payload_count' => count($rows), 'review_note' => 'دریافت با بررسی تعداد'];
    }

    public function test_664_rows_are_validated_without_nested_form_fields(): void
    {
        $rows = $this->rows(664);
        $response = $this->postJson('/test/inbound-transport', $this->payload($rows))->assertOk();
        $this->assertCount(664, $response->json('items'));
        $this->assertSame($rows[663], $response->json('items.663'));
        $this->assertSame('0', $response->json('items.2.accepted_quantity'));
        $this->assertSame('دریافت با بررسی تعداد', $response->json('note'));
    }

    public function test_malformed_object_and_count_mismatch_are_rejected(): void
    {
        foreach (['{bad', '{"id":1}', json_encode($this->rows(2))] as $json) {
            $this->postJson('/test/inbound-transport', array_replace($this->payload($this->rows(3)), ['items_payload' => $json]))->assertUnprocessable()->assertJsonValidationErrors('items_payload');
        }
    }

    public function test_duplicate_ids_and_invalid_quantity_are_not_hidden_by_transport(): void
    {
        $rows = $this->rows(2);
        $rows[1]['id'] = '1';
        $this->postJson('/test/inbound-transport', $this->payload($rows))->assertUnprocessable()->assertJsonValidationErrors('items.1.id');
        $rows = $this->rows(1);
        $rows[0]['accepted_quantity'] = '1.5';
        $this->postJson('/test/inbound-transport', $this->payload($rows))->assertUnprocessable()->assertJsonValidationErrors('items.0.accepted_quantity');
        $rows[0]['accepted_quantity'] = '-1';
        $this->postJson('/test/inbound-transport', $this->payload($rows))->assertUnprocessable()->assertJsonValidationErrors('items.0.accepted_quantity');
    }

    public function test_existing_small_forms_and_version_validation_keep_working(): void
    {
        $this->postJson('/test/inbound-transport', ['receipt_version' => str_repeat('b', 64), 'items' => $this->rows(2)])->assertOk()->assertJsonCount(2, 'items');
        $this->postJson('/test/inbound-transport', array_replace($this->payload($this->rows(1)), ['receipt_version' => '']))->assertUnprocessable()->assertJsonValidationErrors('receipt_version');
        $rows = $this->rows(1);
        $rows[0]['received_warehouse_id'] = '999';
        $this->postJson('/test/inbound-transport', $this->payload($rows))->assertUnprocessable()->assertJsonValidationErrors('items.0.received_warehouse_id');
    }
}
