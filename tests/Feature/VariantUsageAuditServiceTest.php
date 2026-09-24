<?php

use App\Models\ActivityLog;
use App\Models\Category;
use App\Models\PreinvoiceDraftReservation;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Purchase;
use App\Models\Supplier;
use App\Models\Warehouse;
use App\Models\WarehouseStock;
use App\Services\FreshVariantActivityEvidenceService;
use App\Services\ReservationQueryService;
use App\Services\SyntheticDefaultVariantEvidenceService;
use App\Services\VariantReferenceDiscoveryService;
use App\Services\VariantUsageAuditService;
use App\Services\WarehouseStockService;
use Illuminate\Database\QueryException;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function phaseFiveUnusedVariant(array $attributes = []): ProductVariant
{
    $category = Category::query()->create(['name' => 'Usage '.uniqid(), 'code' => uniqid('U')]);
    $product = Product::query()->create([
        'category_id' => $category->id,
        'name' => 'Usage product',
        'sku' => uniqid('USAGE-'),
        'code' => '820001',
        'stock' => 0,
        'reserved' => 0,
        'price' => 100,
        'is_sellable' => true,
    ]);

    return ProductVariant::query()->create(array_merge([
        'product_id' => $product->id,
        'variant_name' => 'Unused',
        'variety_name' => '—',
        'variety_code' => '0000',
        'variant_code' => '82000100000',
        'sell_price' => 0,
        'stock' => 0,
        'reserved' => 0,
        'is_active' => true,
        'sales_enabled' => true,
    ], $attributes));
}

function phaseFiveInsertBulkParityReference(string $table, string $column, ProductVariant $variant): void
{
    DB::statement('PRAGMA defer_foreign_keys = ON');
    $values = [];

    foreach (DB::select('PRAGMA table_info("'.str_replace('"', '""', $table).'")') as $definition) {
        $name = (string) $definition->name;
        if ((int) $definition->pk === 1 || ((int) $definition->notnull === 0 && $name !== $column)) {
            continue;
        }
        if ($definition->dflt_value !== null && $name !== $column) {
            continue;
        }

        $values[$name] = match (true) {
            $name === $column => (int) $variant->id,
            $name === 'product_id' => (int) $variant->product_id,
            $name === 'deactivation_type' => 'variant',
            $name === 'created_at', $name === 'updated_at', str_ends_with($name, '_at'), str_ends_with($name, '_date') => now()->toDateTimeString(),
            str_ends_with($name, '_id') => 1,
            str_contains(strtoupper((string) $definition->type), 'INT') => 1,
            str_contains(strtoupper((string) $definition->type), 'REAL'), str_contains(strtoupper((string) $definition->type), 'DECIMAL') => 1,
            default => 'test',
        };
    }

    $values[$column] = (int) $variant->id;
    DB::table($table)->insert($values);
}

it('marks a zero-authority unreferenced variant safe while allowing zero warehouse rows', function (): void {
    $variant = phaseFiveUnusedVariant();
    WarehouseStock::query()->create([
        'warehouse_id' => WarehouseStockService::centralWarehouseId(),
        'product_id' => $variant->product_id,
        'product_variant_id' => $variant->id,
        'quantity' => 0,
    ]);

    $audit = app(VariantUsageAuditService::class)->audit($variant);

    expect($audit['safe_to_remove'])->toBeTrue()
        ->and($audit['warehouse_stock'])->toBe(0)
        ->and($audit['reserved'])->toBe(0)
        ->and($audit['blocking_reasons'])->toBe([]);
});

it('blocks each direct physical cache mapping and business-evidence class', function (string $case): void {
    $variant = phaseFiveUnusedVariant();

    match ($case) {
        'physical stock' => WarehouseStock::query()->create([
            'warehouse_id' => WarehouseStockService::centralWarehouseId(),
            'product_id' => $variant->product_id,
            'product_variant_id' => $variant->id,
            'quantity' => 2,
        ]),
        'cached stock' => $variant->forceFill(['stock' => 2])->save(),
        'cached reserved' => $variant->forceFill(['reserved' => 2])->save(),
        'external mapping' => $variant->forceFill(['variety_id' => 987])->save(),
        'audit evidence' => ActivityLog::query()->create([
            'action' => 'updated',
            'subject_type' => ProductVariant::class,
            'subject_id' => $variant->id,
            'description' => 'business-significant update',
            'properties' => [],
            'occurred_at' => now(),
        ]),
    };

    $audit = app(VariantUsageAuditService::class)->audit($variant->fresh());

    expect($audit['safe_to_remove'])->toBeFalse()
        ->and($audit['blocking_reasons'])->not->toBeEmpty();
})->with(['physical stock', 'cached stock', 'cached reserved', 'external mapping', 'audit evidence']);

it('blocks known purchase references and unknown schema-discovered references', function (): void {
    $variant = phaseFiveUnusedVariant();
    $supplier = Supplier::query()->create(['name' => 'Phase 5 supplier']);
    $purchase = Purchase::query()->create([
        'supplier_id' => $supplier->id,
        'total_amount' => 1,
        'purchased_at' => now(),
    ]);
    DB::table('purchase_items')->insert([
        'purchase_id' => $purchase->id,
        'product_id' => $variant->product_id,
        'product_variant_id' => $variant->id,
        'product_name' => 'Usage product',
        'product_code' => '820001',
        'quantity' => 1,
        'buy_price' => 1,
        'sell_price' => 1,
        'line_total' => 1,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    Schema::create('phase_five_unknown_refs', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('variant_id');
    });
    DB::table('phase_five_unknown_refs')->insert(['variant_id' => $variant->id]);

    $audit = app(VariantUsageAuditService::class)->audit($variant);

    expect($audit['safe_to_remove'])->toBeFalse()
        ->and($audit['purchase_refs'])->toBe(1)
        ->and($audit['other_reference_tables'])->toContain('phase_five_unknown_refs.variant_id')
        ->and($audit['blocking_reasons'])->toContain('purchase_references')
        ->and($audit['blocking_reasons'])->toContain('unknown_reference:phase_five_unknown_refs.variant_id');
});

it('counts one activity row once when both direct and property routes reference the variant', function (): void {
    $variant = phaseFiveUnusedVariant();
    ActivityLog::query()->create([
        'action' => 'updated',
        'subject_type' => ProductVariant::class,
        'subject_id' => $variant->id,
        'description' => 'same variant through both routes',
        'properties' => ['variant_id' => (int) $variant->id],
        'occurred_at' => now(),
    ]);
    $snapshot = app(SyntheticDefaultVariantEvidenceService::class)->load(collect([$variant]));

    $audit = app(VariantUsageAuditService::class)->auditWithEvidence($variant, $snapshot);

    expect($audit['activity_refs'])->toBe(1)
        ->and($audit['safe_to_remove'])->toBeFalse()
        ->and($audit['blocking_reasons'])->toContain('audit_or_business_evidence');
});

it('matches fresh single-record usage results in the bulk audit path', function (): void {
    $netZero = phaseFiveUnusedVariant();
    $secondWarehouse = Warehouse::query()->create([
        'name' => 'Bulk parity branch',
        'type' => 'branch',
        'is_active' => true,
    ]);
    WarehouseStock::query()->create([
        'warehouse_id' => WarehouseStockService::centralWarehouseId(),
        'product_id' => $netZero->product_id,
        'product_variant_id' => $netZero->id,
        'quantity' => 5,
    ]);
    WarehouseStock::query()->create([
        'warehouse_id' => $secondWarehouse->id,
        'product_id' => $netZero->product_id,
        'product_variant_id' => $netZero->id,
        'quantity' => -5,
    ]);

    $referenced = phaseFiveUnusedVariant([
        'variant_code' => uniqid('BULK-PARITY-'),
        'stock' => 2,
        'reserved' => 3,
        'variety_id' => 44,
    ]);
    Schema::create('phase_five_bulk_unknown_refs', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('variant_id');
    });
    DB::table('phase_five_bulk_unknown_refs')->insert(['variant_id' => $referenced->id]);
    ActivityLog::query()->create([
        'action' => 'updated',
        'subject_type' => ProductVariant::class,
        'subject_id' => $referenced->id,
        'description' => 'bulk parity evidence',
        'properties' => ['variant_id' => (int) $referenced->id],
        'occurred_at' => now(),
    ]);

    $variants = collect([$netZero->fresh('product'), $referenced->fresh('product')]);
    $snapshot = app(SyntheticDefaultVariantEvidenceService::class)->load($variants);
    $service = app(VariantUsageAuditService::class);
    $bulk = $service->auditManyWithEvidence($variants, $snapshot);
    $keys = [
        'warehouse_stock', 'reserved', 'purchase_refs', 'invoice_refs',
        'preinvoice_refs', 'reservation_refs', 'stock_movement_refs',
        'activity_refs', 'reference_counts', 'other_reference_tables',
        'blocking_reasons', 'safe_to_remove',
    ];

    foreach ($variants as $variant) {
        $single = $service->audit($variant);
        foreach ($keys as $key) {
            expect($bulk->get((int) $variant->id)[$key])->toBe($single[$key]);
        }
    }
});

it('queries each discovered reference definition once for a bulk candidate chunk', function (): void {
    $variants = collect([
        phaseFiveUnusedVariant(['variant_code' => uniqid('BULK-A-')]),
        phaseFiveUnusedVariant(['variant_code' => uniqid('BULK-B-')]),
        phaseFiveUnusedVariant(['variant_code' => uniqid('BULK-C-')]),
    ])
        ->map(fn (ProductVariant $variant): ProductVariant => $variant->fresh('product'));
    $snapshot = app(SyntheticDefaultVariantEvidenceService::class)->load($variants);
    $purchaseReferenceQueries = [];
    DB::listen(function ($query) use (&$purchaseReferenceQueries): void {
        $sql = strtolower($query->sql);
        if (str_contains($sql, 'purchase_items') && str_contains($sql, 'group by')) {
            $purchaseReferenceQueries[] = $query->sql;
        }
    });

    app(VariantUsageAuditService::class)->auditManyWithEvidence($variants, $snapshot);

    expect($purchaseReferenceQueries)->toHaveCount(1);
});

it('fails closed when a discovered bulk reference can no longer be queried', function (): void {
    $variant = phaseFiveUnusedVariant()->fresh('product');
    Schema::create('phase_five_disappearing_refs', function (Blueprint $table): void {
        $table->id();
        $table->unsignedBigInteger('variant_id');
    });
    $references = app(VariantReferenceDiscoveryService::class);
    $references->discover();
    Schema::drop('phase_five_disappearing_refs');
    $snapshot = app(SyntheticDefaultVariantEvidenceService::class)->load(collect([$variant]));

    $service = new VariantUsageAuditService($references, app(ReservationQueryService::class), app(FreshVariantActivityEvidenceService::class));

    expect(fn () => $service->auditManyWithEvidence(collect([$variant]), $snapshot))
        ->toThrow(QueryException::class);
});

it('matches single-record auditing for a populated known reference', function (string $table, string $column): void {
    $variant = phaseFiveUnusedVariant(['variant_code' => uniqid('KNOWN-PARITY-')])->fresh('product');
    phaseFiveInsertBulkParityReference($table, $column, $variant);
    $snapshot = app(SyntheticDefaultVariantEvidenceService::class)->load(collect([$variant]));
    $service = app(VariantUsageAuditService::class);

    $single = $service->audit($variant);
    $bulk = $service->auditManyWithEvidence(collect([$variant]), $snapshot)->get((int) $variant->id);

    expect($single['reference_counts'][$table.'.'.$column])->toBeGreaterThan(0)
        ->and($bulk['reference_counts'])->toBe($single['reference_counts'])
        ->and($bulk['blocking_reasons'])->toBe($single['blocking_reasons'])
        ->and($bulk['safe_to_remove'])->toBe($single['safe_to_remove']);
})->with(collect(VariantReferenceDiscoveryService::KNOWN_REFERENCES)
    ->flatMap(fn (array $columns, string $table): array => collect($columns)
        ->mapWithKeys(fn (string $column): array => [$table.'.'.$column => [$table, $column]])
        ->all())
    ->all());

it('matches canonical reservation quantities in single and bulk auditing', function (): void {
    $variant = phaseFiveUnusedVariant(['variant_code' => uniqid('RESERVED-PARITY-')])->fresh('product');
    PreinvoiceDraftReservation::query()->create([
        'token' => uniqid('reservation-'),
        'product_id' => $variant->product_id,
        'variant_id' => $variant->id,
        'quantity' => 7,
        'reservation_scope' => PreinvoiceDraftReservation::SCOPE_TEMPORARY_ONLINE,
        'expires_at' => now()->addHour(),
        'last_seen_at' => now(),
    ]);
    $snapshot = app(SyntheticDefaultVariantEvidenceService::class)->load(collect([$variant]));
    $service = app(VariantUsageAuditService::class);

    $single = $service->audit($variant);
    $bulk = $service->auditManyWithEvidence(collect([$variant]), $snapshot)->get((int) $variant->id);

    expect($single['reserved'])->toBe(7)
        ->and($bulk['reserved'])->toBe(7)
        ->and($bulk['blocking_reasons'])->toBe($single['blocking_reasons'])
        ->and($bulk['safe_to_remove'])->toBeFalse();
});
