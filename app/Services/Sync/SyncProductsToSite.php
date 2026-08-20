<?php

namespace App\Services\Sync;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Site\Price as SitePrice;
use App\Models\Site\Product as SiteProduct;
use App\Models\WarehouseStock;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Throwable;

class SyncProductsToSite {
    private const CHUNK_SIZE = 200;

    private const EMPTY_VARIANT_NAMES = [
        '—',
        '–',
        '-',
        'ــ',
        'بدون تنوع',
        'بدون مدل',
    ];

    /**
     * Executes one reconciliation cycle.
     *
     * Every execution compares the source data with the Site data and only writes
     * records whose synchronized fields are actually different.
     */
    public function syncAll(): array {
        $startedAt = CarbonImmutable::now();

        $stats = [
            'mode'              => 'full-diff',
            'started_at'        => $startedAt->toIso8601String(),
            'products_checked'  => 0,
            'products_created'  => 0,
            'products_updated'  => 0,
            'products_skipped'  => 0,
            'variants_checked'  => 0,
            'variants_created'  => 0,
            'variants_updated'  => 0,
            'variants_deleted'  => 0,
            'variants_skipped'  => 0,
            'variants_ignored'  => 0,
            'attributes_synced' => 0,
            'errors'            => 0,
        ];

        try {
            $this->syncProducts($stats);
            $this->syncVariants($stats);

            $stats['finished_at'] = CarbonImmutable::now()
                ->toIso8601String();

            return $stats;
        } catch ( Throwable $exception ) {
            $stats['errors'] ++;
            $stats['finished_at'] = CarbonImmutable::now()
                ->toIso8601String();

            Log::error('Direct Inventory -> Site product synchronization failed.', [
                'message'   => $exception->getMessage(),
                'exception' => $exception::class,
                'stats'     => $stats,
            ]);

            throw $exception;
        }
    }

    /**
     * Reconciles all products against the Site connection in chunks.
     */
    private function syncProducts( array &$stats ): void {
        Product::query()
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ( EloquentCollection $products ) use ( &$stats ): void {
                if ( $products->isEmpty() ) {
                    return;
                }

                $productIds = $products->pluck('id')
                    ->map(static fn( $id ): int => (int) $id)
                    ->values()
                    ->all();

                $siteProducts = SiteProduct::query()
                    ->whereIn('id', $productIds)
                    ->get()
                    ->keyBy('id');

                foreach ( $products as $sourceProduct ) {
                    $stats['products_checked'] ++;

                    /** @var SiteProduct|null $siteProduct */
                    $siteProduct = $siteProducts->get((int) $sourceProduct->id);
                    $desiredData = $this->makeProductData($sourceProduct);

                    if ( $siteProduct !== null && !$this->productHasDifferences($siteProduct, $desiredData) ) {
                        $stats['products_skipped'] ++;
                        continue;
                    }

                    $created = $siteProduct === null;
                    $this->saveProduct($sourceProduct, $siteProduct, $desiredData);

                    $stats[$created ? 'products_created' : 'products_updated'] ++;
                }
            });
    }

    /**
     * Reconciles every current variant. Stock is calculated from warehouse rows,
     * so a stock mismatch is also fixed even when product_variants.updated_at did
     * not change.
     */
    private function syncVariants( array &$stats ): void {
        ProductVariant::query()
            ->orderBy('id')
            ->chunkById(self::CHUNK_SIZE, function ( EloquentCollection $variants ) use ( &$stats ): void {
                if ( $variants->isEmpty() ) {
                    return;
                }

                $variantIds = $variants->pluck('id')
                    ->map(static fn( $id ): int => (int) $id)
                    ->values()
                    ->all();

                $productIds = $variants->pluck('product_id')
                    ->filter(static fn( $id ): bool => is_numeric($id) && (int) $id > 0)
                    ->map(static fn( $id ): int => (int) $id)
                    ->unique()
                    ->values()
                    ->all();

                $stockRows = WarehouseStock::query()
                    ->whereIn('product_variant_id', $variantIds)
                    ->groupBy('product_variant_id')
                    ->select([
                        'product_variant_id',
                        DB::raw('SUM(quantity) as total_stock'),
                        DB::raw('MAX(updated_at) as stock_updated_at'),
                    ])
                    ->get()
                    ->keyBy('product_variant_id');

                $sitePrices = SitePrice::withTrashed()
                    ->whereIn('id', $variantIds)
                    ->get()
                    ->keyBy('id');

                $siteProducts = SiteProduct::query()
                    ->whereIn('id', $productIds)
                    ->get()
                    ->keyBy('id');

                $sourceProducts = Product::query()
                    ->whereIn('id', $productIds)
                    ->get()
                    ->keyBy('id');

                // We pass every variant name through the attribute reconciler so an
                // attribute mismatch can be repaired even when the price row itself
                // is already correct.
                $variantNames = [];

                foreach ( $variants as $variant ) {
                    $stats['variants_checked'] ++;

                    $variantId = (int) $variant->id;

                    /** @var SitePrice|null $sitePrice */
                    $sitePrice = $sitePrices->get($variantId);
                    $stockRow  = $stockRows->get($variantId);

                    $sourceTimestamp = $this->maxTimestamp($variant->updated_at, $stockRow?->stock_updated_at);

                    $priceData = $this->makePriceData($variant, (int) ( $stockRow?->total_stock ?? 0 ), $sourceTimestamp);

                    if ( $priceData === null ) {
                        $variantNames[$variantId] = null;

                        if ( $sitePrice !== null && !$sitePrice->trashed() ) {
                            $sitePrice->delete();
                            $stats['variants_deleted'] ++;
                        }
                        else {
                            $stats['variants_ignored'] ++;
                        }

                        continue;
                    }

                    $variantNames[$variantId] = $priceData['variant_name'];

                    /** @var SiteProduct|null $siteProduct */
                    $siteProduct = $siteProducts->get((int) $variant->product_id);

                    if ( $siteProduct === null ) {
                        /** @var Product|null $sourceProduct */
                        $sourceProduct = $sourceProducts->get((int) $variant->product_id);

                        if ( $sourceProduct === null ) {
                            $stats['variants_ignored'] ++;
                            continue;
                        }

                        $siteProduct = $this->saveProduct($sourceProduct, null, $this->makeProductData($sourceProduct));

                        $siteProducts->put((int) $siteProduct->id, $siteProduct);
                        $stats['products_created'] ++;
                    }

                    $needsRestore = $sitePrice !== null && $sitePrice->trashed();
                    $hasDiff      = $sitePrice === null || $this->priceHasDifferences($sitePrice, $priceData);

                    if ( !$hasDiff && !$needsRestore ) {
                        $stats['variants_skipped'] ++;
                        continue;
                    }

                    $created   = $sitePrice === null;
                    $sitePrice = $this->savePrice($siteProduct, $sitePrice, $priceData);

                    $stats[$created ? 'variants_created' : 'variants_updated'] ++;
                }

                if ( $variantNames !== [] ) {
                    $stats['attributes_synced'] += $this->syncVariantNameAttributes($variantNames);
                }
            });
    }

    /**
     * @return array<string, mixed>
     */
    private function makeProductData( Product $source ): array {
        return [
            'id'              => $source->id,
            'title'           => trim((string) $source->name),
            'product_code'    => $source->code,
            'image'           => $source->image_path,
            'published'       => (bool) ( $source->is_sellable ?? false ),
            'type'            => 'physical',
            'price_type'      => 'multiple-price',
            'unit'            => 'عدد',
            'special'         => '0',
            'rounding_type'   => 'default',
            'rounding_amount' => 'default',
            'slug'            => (string) $source->id,
            'lang'            => 'fa',
            'updated_at'      => $this->dateTimeString($source->updated_at) ?? now()->toDateTimeString(),
        ];
    }

    private function saveProduct( Product $source, ?SiteProduct $siteProduct, ?array $data = null ): SiteProduct {
        $siteProduct ??= new SiteProduct();
        $isNew       = !$siteProduct->exists;
        $data        ??= $this->makeProductData($source);

        $siteProduct->timestamps = false;
        $siteProduct->forceFill($data + [
                'created_at' => $isNew ? ( $this->dateTimeString($source->created_at) ?? now()->toDateTimeString() ) : $siteProduct->created_at,
            ]);
        $siteProduct->save();

        return $siteProduct;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function makePriceData( ProductVariant $variant, int $stock, CarbonInterface|string|null $sourceTimestamp ): ?array {
        $sellPrice     = (int) ( $variant->sell_price ?? 0 );
        $regularPrice  = (int) ( $variant->regular_price ?? $sellPrice );
        $discountPrice = (int) ( $variant->discount_price ?? 0 );
        $isActive      = (bool) ( $variant->is_active ?? true );
        $salesEnabled  = (bool) ( $variant->sales_enabled ?? true );

        if ( !$isActive || !$salesEnabled ) {
            $stock = 0;
        }

        $finalPrice = $this->calculateFinalPrice($sellPrice, $regularPrice, $discountPrice);

        if ( $finalPrice <= 0 ) {
            return null;
        }

        $variantCode = filled($variant->variant_code) ? trim((string) $variant->variant_code) : SitePrice::generateInventoryVariantCode((int) $variant->id);

        return [
            'id'                  => (int) $variant->id,
            'external_variant_id' => (string) $variant->id,
            'variant_code'        => $variantCode,
            'variant_name'        => $this->resolveVariantName($variant),
            'product_id'          => (int) $variant->product_id,
            'price'               => $finalPrice,
            'regular_price'       => $regularPrice,
            'discount'            => $this->calculateDiscount($sellPrice, $regularPrice, $discountPrice),
            'discount_price'      => $finalPrice,
            'stock'               => $stock,
            'discount_expire_at'  => $this->parseDate($variant->discount_expire_at ?? null),
            'updated_at'          => $sourceTimestamp ? CarbonImmutable::parse($sourceTimestamp)
                ->toDateTimeString() : now()->toDateTimeString(),
        ];
    }

    private function savePrice( SiteProduct $siteProduct, ?SitePrice $sitePrice, array $data ): SitePrice {
        $sitePrice ??= new SitePrice();
        $isNew     = !$sitePrice->exists;

        $sitePrice->timestamps = false;
        $sitePrice->forceFill($data + [
                'created_at' => $isNew ? now()->toDateTimeString() : $sitePrice->created_at,
            ]);
        $sitePrice->product()
            ->associate($siteProduct);
        $sitePrice->save();

        if ( $sitePrice->trashed() ) {
            // restore() may touch updated_at, so restore and then put the source
            // timestamp back explicitly.
            $sitePrice->restore();
            $sitePrice->timestamps = false;
            $sitePrice->forceFill([
                'updated_at' => $data['updated_at'],
            ])
                ->save();
        }

        return $sitePrice;
    }

    /**
     * Compare only synchronized business fields. updated_at/created_at are not used
     * to decide whether a row is correct.
     *
     * @param array<string, mixed> $desired
     */
    private function productHasDifferences( SiteProduct $siteProduct, array $desired ): bool {
        $fields = [
            'id',
            'title',
            'product_code',
            'image',
            'published',
            'type',
            'price_type',
            'unit',
            'special',
            'rounding_type',
            'rounding_amount',
            'slug',
            'lang',
        ];

        foreach ( $fields as $field ) {
            if ( !$this->valuesMatch($field, $siteProduct->getAttribute($field), $desired[$field] ?? null) ) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $desired
     */
    private function priceHasDifferences( SitePrice $sitePrice, array $desired ): bool {
        $fields = [
            'id',
            'external_variant_id',
            'variant_code',
            'variant_name',
            'product_id',
            'price',
            'regular_price',
            'discount',
            'discount_price',
            'stock',
            'discount_expire_at',
        ];

        foreach ( $fields as $field ) {
            if ( !$this->valuesMatch($field, $sitePrice->getAttribute($field), $desired[$field] ?? null) ) {
                return true;
            }
        }

        return false;
    }

    private function valuesMatch( string $field, mixed $actual, mixed $expected ): bool {
        if ( in_array($field, [
            'id',
            'product_id',
            'price',
            'regular_price',
            'discount',
            'discount_price',
            'stock',
        ], true) ) {
            return (int) $actual === (int) $expected;
        }

        if ( $field === 'published' ) {
            return (bool) $actual === (bool) $expected;
        }

        if ( $field === 'discount_expire_at' ) {
            return $this->dateTimeString($actual) === $this->dateTimeString($expected);
        }

        if ( in_array($field, [ 'image', 'variant_name' ], true) ) {
            return $this->nullableString($actual) === $this->nullableString($expected);
        }

        return (string) ( $actual ?? '' ) === (string) ( $expected ?? '' );
    }

    private function nullableString( mixed $value ): ?string {
        if ( $value === null ) {
            return null;
        }

        $value = trim((string) $value);

        return $value === '' ? null : $value;
    }

    private function dateTimeString( mixed $value ): ?string {
        if ( $value === null || $value === '' ) {
            return null;
        }

        return CarbonImmutable::parse($value)
            ->toDateTimeString();
    }

    private function calculateDiscount( int $sellPrice, int $regularPrice, int $discountPrice ): int {
        if ( $regularPrice > 0 && $discountPrice > 0 && $discountPrice < $regularPrice ) {
            return (int) round(( ( $regularPrice - $discountPrice ) / $regularPrice ) * 100);
        }

        if ( $regularPrice > 0 && $sellPrice >= 0 && $sellPrice < $regularPrice ) {
            return (int) round(( ( $regularPrice - $sellPrice ) / $regularPrice ) * 100);
        }

        return 0;
    }

    private function calculateFinalPrice( int $sellPrice, int $regularPrice, int $discountPrice ): int {
        if ( $discountPrice > 0 && $discountPrice < $regularPrice ) {
            return $discountPrice;
        }

        return $sellPrice;
    }

    private function parseDate( mixed $date ): ?string {
        if ( blank($date) ) {
            return null;
        }

        return CarbonImmutable::parse($date)
            ->toDateTimeString();
    }

    private function resolveVariantName( ProductVariant $variant ): ?string {
        $variantName = $this->normalizeVariantName($variant->variant_name ?? null);
        $varietyName = $this->normalizeVariantName($variant->variety_name ?? null);

        if ( in_array($variantName, self::EMPTY_VARIANT_NAMES, true) ) {
            $variantName = null;
        }

        if ( in_array($varietyName, self::EMPTY_VARIANT_NAMES, true) ) {
            $varietyName = null;
        }

        if ( $variantName !== null && $varietyName !== null && $variantName !== $varietyName ) {
            return mb_substr($variantName . ' - ' . $varietyName, 0, 255);
        }

        return $variantName ?? $varietyName;
    }

    private function normalizeVariantName( mixed $name ): ?string {
        if ( !is_string($name) ) {
            return null;
        }

        $name = trim($name);

        return $name === '' ? null : $name;
    }

    /**
     * Synchronizes the "مدل" attribute used by Site prices.
     *
     * This method also removes an old attribute relation when the source variant no
     * longer has a model name.
     *
     * @param array<int, string|null> $variantNames [price_id => variant_name]
     */
    private function syncVariantNameAttributes( array $variantNames ): int {
        if ( $variantNames === [] ) {
            return 0;
        }

        $names = collect($variantNames)
            ->filter(static fn( $name ): bool => is_string($name) && trim($name) !== '')
            ->map(static fn( string $name ): string => trim($name))
            ->unique()
            ->values();

        $connection = DB::connection('site');
        $now        = now()->toDateTimeString();

        return $connection->transaction(function () use ( $connection, $variantNames, $names, $now ): int {
            $attributeGroup = $connection->table('attribute_groups')
                ->where('name', 'مدل')
                ->where('lang', app()->getLocale())
                ->first();

            // If there are no desired names and the group never existed, there is
            // nothing to reconcile.
            if ( $attributeGroup === null && $names->isEmpty() ) {
                return 0;
            }

            if ( $attributeGroup === null ) {
                $ordering = (int) $connection->table('attribute_groups')
                        ->max('ordering') + 1;

                $groupData = [
                    'name'       => 'مدل',
                    'type'       => 'select',
                    'lang'       => app()->getLocale(),
                    'ordering'   => $ordering,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                if ( Schema::connection('site')
                    ->hasColumn('attribute_groups', 'deleted_at') ) {
                    $groupData['deleted_at'] = null;
                }

                $groupId = (int) $connection->table('attribute_groups')
                    ->insertGetId($groupData);
            }
            else {
                $groupId = (int) $attributeGroup->id;

                // Restore the group only when we actually need to attach names.
                if ( !$names->isEmpty()
                     && Schema::connection('site')
                         ->hasColumn('attribute_groups', 'deleted_at')
                     && $attributeGroup->deleted_at !== null ) {
                    $connection->table('attribute_groups')
                        ->where('id', $groupId)
                        ->update([
                            'deleted_at' => null,
                            'updated_at' => $now,
                        ]);
                }
            }

            $attributesByName = collect();

            if ( !$names->isEmpty() ) {
                $attributesByName = $connection->table('attributes')
                    ->where('attribute_group_id', $groupId)
                    ->whereIn('name', $names->all())
                    ->get([ 'id', 'name' ])
                    ->keyBy('name');

                foreach ( $names as $name ) {
                    if ( $attributesByName->has($name) ) {
                        continue;
                    }

                    $attributeId = (int) $connection->table('attributes')
                        ->insertGetId([
                            'attribute_group_id' => $groupId,
                            'name'               => $name,
                            'value'              => null,
                            'created_at'         => $now,
                            'updated_at'         => $now,
                        ]);

                    $attributesByName->put($name, (object) [
                        'id'   => $attributeId,
                        'name' => $name,
                    ]);
                }
            }

            $groupAttributeIds = $connection->table('attributes')
                ->where('attribute_group_id', $groupId)
                ->pluck('id')
                ->map(static fn( $id ): int => (int) $id)
                ->all();

            $priceIds = collect(array_keys($variantNames))
                ->map(static fn( $id ): int => (int) $id)
                ->values()
                ->all();

            $existingRelations = collect();

            if ( $groupAttributeIds !== [] && $priceIds !== [] ) {
                $existingRelations = $connection->table('attribute_price')
                    ->whereIn('price_id', $priceIds)
                    ->whereIn('attribute_id', $groupAttributeIds)
                    ->get([ 'price_id', 'attribute_id' ])
                    ->groupBy(static fn( $row ): int => (int) $row->price_id);
            }

            $synced = 0;

            foreach ( $variantNames as $priceId => $name ) {
                $priceId = (int) $priceId;

                $priceExists = $connection->table('prices')
                    ->where('id', $priceId)
                    ->whereNull('deleted_at')
                    ->exists();

                $normalizedName = is_string($name) ? trim($name) : '';
                $desiredAttrId  = null;

                if ( $normalizedName !== '' && $attributesByName->has($normalizedName) ) {
                    $desiredAttrId = (int) $attributesByName->get($normalizedName)->id;
                }

                $currentIds = $existingRelations->get($priceId, collect())
                    ->pluck('attribute_id')
                    ->map(static fn( $id ): int => (int) $id)
                    ->sort()
                    ->values()
                    ->all();

                $desiredIds = $priceExists && $desiredAttrId !== null ? [ $desiredAttrId ] : [];
                sort($desiredIds);

                if ( $currentIds === $desiredIds ) {
                    continue;
                }

                if ( $groupAttributeIds !== [] ) {
                    $connection->table('attribute_price')
                        ->where('price_id', $priceId)
                        ->whereIn('attribute_id', $groupAttributeIds)
                        ->delete();
                }

                if ( $priceExists && $desiredAttrId !== null ) {
                    $connection->table('attribute_price')
                        ->insert([
                            'attribute_id' => $desiredAttrId,
                            'price_id'     => $priceId,
                            'created_at'   => $now,
                            'updated_at'   => $now,
                        ]);
                }

                $synced ++;
            }

            return $synced;
        });
    }

    private function maxTimestamp( mixed ...$timestamps ): ?CarbonImmutable {
        $values = collect($timestamps)
            ->filter()
            ->map(static fn( $value ): CarbonImmutable => CarbonImmutable::parse($value));

        if ( $values->isEmpty() ) {
            return null;
        }

        /** @var CarbonImmutable $max */
        $max = $values->sortByDesc(static fn( CarbonImmutable $date ): int => $date->getTimestamp())
            ->first();

        return $max;
    }
}
