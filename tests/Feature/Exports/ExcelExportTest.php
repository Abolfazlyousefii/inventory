<?php

use App\Exports\ProductRowsExport;
use App\Exports\PurchasesExport;
use App\Exports\SalesReturnsExport;
use App\Services\SalesReturnReportService;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\FromQuery;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

it('exports product rows with headings and mapped excel output', function (): void {
    $row = [
        'image_url' => 'https://example.test/p.png',
        'name' => 'کالا',
        'sku' => 'SKU-1',
        'category' => 'دسته',
        'stock' => 10,
        'price' => 250000,
        'stock_status' => 'موجود',
        'updated_at' => '2026/07/15',
        'unit' => 'عدد',
        'barcode' => '626000000001',
    ];

    $export = new ProductRowsExport([$row]);

    expect($export)->toBeInstanceOf(FromArray::class)
        ->and($export)->toBeInstanceOf(WithHeadings::class)
        ->and($export)->toBeInstanceOf(WithMapping::class)
        ->and($export->headings())->toHaveCount(10)
        ->and($export->array())->toBe([$row])
        ->and($export->map($row))->toBe(array_values($row));
});

it('builds the purchases excel export query and headings for saved purchase rows', function (): void {
    $export = new PurchasesExport();

    expect($export)->toBeInstanceOf(FromQuery::class)
        ->and($export)->toBeInstanceOf(WithHeadings::class)
        ->and($export)->toBeInstanceOf(WithMapping::class)
        ->and($export->headings())->toContain('شناسه خرید', 'نام کالا', 'جمع نهایی ردیف (ریال)')
        ->and($export->query()->getModel()->getTable())->toBe('purchase_items');
});

it('exports sales return rows with report filters headings and mapped totals', function (): void {
    $service = Mockery::mock(SalesReturnReportService::class);
    $service->shouldReceive('getExcelRows')
        ->once()
        ->with(['status' => 'draft'])
        ->andReturn(new Collection([
            [
                'document_number' => 'SR-1',
                'customer_name' => 'مشتری',
                'returned_at_display' => '1405/04/24',
                'return_type' => 'داخلی',
                'healthy_amount' => 1000,
                'damaged_amount' => 200,
                'total_amount' => 1200,
            ],
        ]));

    $export = new SalesReturnsExport(['status' => 'draft'], $service);
    $row = $export->collection()->first();

    expect($export)->toBeInstanceOf(FromCollection::class)
        ->and($export->headings())->toHaveCount(8)
        ->and($export->map($row))->toBe([1, 'SR-1', 'مشتری', '1405/04/24', 'داخلی', 1000, 200, 1200])
        ->and($export->columnFormats())->toHaveKeys(['F', 'G', 'H'])
        ->and($export->columnWidths())->toHaveKeys(['A', 'B', 'H']);
});
