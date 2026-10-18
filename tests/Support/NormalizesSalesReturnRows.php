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
        $user->shouldReceive('can')->andReturn($authorized);
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
