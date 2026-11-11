<?php

namespace Tests\Feature;

use App\Http\Controllers\StockMovementReportController;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StockMovementPurchaseReturnReportTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('products', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('sku');
            $table->timestamps();
        });

        Schema::create('stock_movements', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('product_id');
            $table->unsignedBigInteger('user_id')->nullable();
            $table->string('type');
            $table->string('reason');
            $table->unsignedInteger('quantity');
            $table->string('reference')->nullable();
            $table->timestamps();
        });

        DB::table('products')->insert([
            'id' => 1306,
            'name' => 'واچ و ایرپاد S20 Pro Max',
            'sku' => 'S20-PRO-MAX',
        ]);

        foreach (['purchase_return', 'purchase_return_restore', 'sales_return', 'return', 'purchase'] as $index => $reason) {
            DB::table('stock_movements')->insert([
                'product_id' => 1306,
                'type' => $reason === 'purchase_return_restore' ? 'in' : 'out',
                'reason' => $reason,
                'quantity' => $index + 1,
                'reference' => $reason === 'purchase_return' ? 'PR-00004' : 'TEST',
                'created_at' => '2026-11-10 12:00:00',
                'updated_at' => '2026-11-10 12:00:00',
            ]);
        }
    }

    public function test_returns_filter_includes_purchase_return_and_reversal_movements(): void
    {
        $reasons = $this->reasonsFor('return');

        $this->assertSame(
            ['purchase_return', 'purchase_return_restore', 'return', 'sales_return'],
            $reasons
        );
    }

    public function test_purchase_return_filter_excludes_other_return_types(): void
    {
        $this->assertSame(
            ['purchase_return', 'purchase_return_restore'],
            $this->reasonsFor('purchase_return')
        );
    }

    public function test_unfiltered_report_preserves_purchase_return_movement(): void
    {
        $this->assertContains('purchase_return', $this->reasonsFor(null));
    }

    public function test_report_has_explicit_purchase_return_filter_and_labels(): void
    {
        $view = file_get_contents(resource_path('views/movements/index.blade.php'));
        $this->assertStringContainsString('value="purchase_return"', $view);
        $this->assertStringContainsString("'purchase_return'=>'برگشت از خرید'", $view);
        $this->assertStringContainsString("'purchase_return_restore'=>'بازگردانی برگشت از خرید'", $view);
    }

    private function reasonsFor(?string $reason): array
    {
        $params = ['q' => 'S20'];
        if ($reason !== null) {
            $params['reason'] = $reason;
        }

        $response = app(StockMovementReportController::class)->index(
            Request::create('/movements', 'GET', $params)
        );

        $reasons = $response->getData()['movements']
            ->getCollection()
            ->pluck('reason')
            ->all();

        sort($reasons);

        return $reasons;
    }
}
