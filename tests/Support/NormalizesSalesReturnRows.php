<?php

namespace Tests\Support;

use App\Models\SalesReturnDocument;
use App\Models\User;
use App\Services\SalesReturnReportService;

trait NormalizesSalesReturnRows
{
    private function returnRow(string $status, bool $authorized = true): array
    {
        $user = \Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('isAdministrator')->andReturn(false);
        $user->shouldReceive('hasRole')->andReturn(false);
        $user->shouldReceive('hasPermission')->andReturnUsing(fn ($permission) => $authorized
            && in_array($permission, ['sales_returns.edit_draft', 'sales_returns.cancel_draft', 'sales_returns.edit_applied'], true));
        $this->actingAs($user);
        // These row-formatting fixtures represent an unreceived virtual return.
        // Receiving/stock locks have their own database-backed regression suite.
        if (! \Illuminate\Support\Facades\Schema::hasTable('warehouse_inbound_receipts')) {
            \Illuminate\Support\Facades\Schema::create('warehouse_inbound_receipts', function ($table) {
                $table->id();
                $table->string('source_type');
                $table->unsignedBigInteger('source_id');
                $table->string('status');
            });
        }
        $this->mock(\App\Services\SalesReturnCorrectionService::class,
            fn ($mock) => $mock->shouldReceive('requiresWarehouse')->andReturn(false));
        $document = new SalesReturnDocument([
            'id' => 42, 'status' => $status, 'document_number' => '00042',
            'total_quantity' => 0,
        ]);
        $document->setAttribute('id', 42);
        $document->setRelation('items', collect());
        $document->setRelation('customer', null);
        $document->setRelation('creator', null);

        return app(SalesReturnReportService::class)->normalizeNewRow($document);
    }
}
