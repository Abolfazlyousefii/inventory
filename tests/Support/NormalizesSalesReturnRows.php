<?php

namespace Tests\Support;

use App\Models\SalesReturnDocument;
use App\Models\User;
use App\Services\SalesReturnReportService;

trait NormalizesSalesReturnRows
{
    private function returnRow(string $status, bool $authorized = true): array
    {
        // The report service now evaluates real ERP route permissions and
        // finalized inbound receipts. Keep this isolated unit fixture independent
        // from Spatie's roles relation and provide the minimal receipt table.
        $user = \Mockery::mock(User::class)->makePartial();
        $user->shouldReceive('isAdministrator')->andReturn(false);
        $user->shouldReceive('hasPermission')->andReturn($authorized);
        $user->shouldReceive('can')->andReturn($authorized);
        if (! \Illuminate\Support\Facades\Schema::hasTable('warehouse_inbound_receipts')) {
            \Illuminate\Support\Facades\Schema::create('warehouse_inbound_receipts', function (\Illuminate\Database\Schema\Blueprint $table) {
                $table->id();
                $table->string('source_type');
                $table->unsignedBigInteger('source_id');
                $table->string('status');
                $table->timestamps();
            });
        }
        $this->actingAs($user);
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
