<?php

use App\Services\VariantReferenceDiscoveryService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

it('discovers registered variant references and undeclared conventional columns', function (): void {
    Schema::create('phase_five_external_variant_links', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('product_variant_id');
    });

    $references = app(VariantReferenceDiscoveryService::class)->discover()
        ->keyBy(fn (array $row) => $row['table'].'.'.$row['column']);

    foreach ([
        'purchase_items.product_variant_id',
        'invoice_items.variant_id',
        'preinvoice_order_items.variant_id',
        'preinvoice_draft_reservations.variant_id',
        'warehouse_stocks.product_variant_id',
        'stock_movements.product_variant_id',
        'warehouse_transfer_items.product_variant_id',
        'stock_count_document_items.product_variant_id',
        'warehouse_location_stocks.product_variant_id',
        'warehouse_location_movements.product_variant_id',
        'price_change_document_items.product_variant_id',
        'sales_return_document_items.created_variant_id',
        'commission_ledger_entries.product_variant_id',
        'warehouse_inbound_receipt_items.product_variant_id',
        'seller_sales_document_items.product_variant_id',
    ] as $key) {
        expect($references->has($key), $key)->toBeTrue()
            ->and($references->get($key)['known'])->toBeTrue();
    }

    expect($references->get('phase_five_external_variant_links.product_variant_id')['known'])->toBeFalse();
    expect($references->filter(fn (array $row) => ! $row['known'])->keys()->values()->all())
        ->toBe(['phase_five_external_variant_links.product_variant_id']);
});

it('discovers schema metadata once per service instance', function (): void {
    $metadataQueries = [];
    DB::listen(function ($query) use (&$metadataQueries): void {
        $sql = strtolower($query->sql);
        if (str_contains($sql, 'sqlite_master') || str_contains($sql, 'pragma table_info') || str_contains($sql, 'pragma foreign_key_list')) {
            $metadataQueries[] = $query->sql;
        }
    });
    $service = app(VariantReferenceDiscoveryService::class);

    $first = $service->discover();
    $firstCount = count($metadataQueries);
    $first->pop();
    $second = $service->discover();

    expect($firstCount)->toBeGreaterThan(0)
        ->and(count($metadataQueries))->toBe($firstCount)
        ->and($second)->not->toBeEmpty()
        ->and($second->count())->toBeGreaterThan($first->count());
});
