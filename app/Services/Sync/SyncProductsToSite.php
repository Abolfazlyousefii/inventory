<?php

namespace App\Services\Sync;

use App\Models\IntegrationSyncState;
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
use Illuminate\Support\Str;
use Throwable;

class SyncProductsToSite {
    private const INTEGRATION            = 'inventory-to-site-direct';
    private const STREAM                 = 'products';
    private const CHUNK_SIZE             = 200;
    private const CURSOR_OVERLAP_SECONDS = 2;

    private const EMPTY_VARIANT_NAMES = [
        '—',
        '–',
        '-',
        'ــ',
        'بدون تنوع',
        'بدون مدل',
    ];

    /**
     * Executes exactly one synchronization cycle.
     *
     * The scheduler is responsible for calling this method every 5/10 seconds.
     */
    public function syncAll(): array {
        $startedAt = CarbonImmutable::now();
        $state     = IntegrationSyncState::query()
            ->firstOrNew([
                'integration' => self::INTEGRATION,
                'stream'      => self::STREAM,
            ]);

        $cursor = $state->last_succeeded_at ? CarbonImmutable::parse($state->last_succeeded_at)
            ->subSeconds(self::CURSOR_OVERLAP_SECONDS) : null;

        $stats = [
            'mode'              => $cursor === null ? 'full' : 'incremental',
            'started_at'        => $startedAt->toIso8601String(),
            'cursor'            => $cursor?->toIso8601String(),
            'products_checked'  => 0,
            'products_created'  => 0,
            'products_updated'  => 0,
            'products_skipped'  => 0,
            'variants_checked'  => 0,
            'variants_created'  => 0,
            'variants_updated'  => 0,
            'variants_skipped'  => 0,
            'variants_ignored'  => 0,
            'attributes_synced' => 0,
            'errors'            => 0,
        ];

        $state->fill([
            'last_started_at' => $startedAt,
            'last_error'      => null,
        ])
            ->save();

        try {
            $changedProductIds = $this->syncProducts($cursor, $startedAt, $stats);
            $this->syncVariants($cursor, $startedAt, $changedProductIds, $stats);

            $stats['finished_at'] = CarbonImmutable::now()
                ->toIso8601String();

            $state->fill([
                'last_succeeded_at' => $startedAt,
                'last_error'        => null,
                'metadata'          => $stats,
            ])
                ->save();

            return $stats;
        } catch ( Throwable $exception ) {
            $stats['errors'] ++;
            $stats['finished_at'] = CarbonImmutable::now()
                ->toIso8601String();

            $state->fill([
                'last_failed_at' => now(),
                'last_error'     => mb_substr($exception->getMessage(), 0, 500),
                'metadata'       => $stats,
            ])
                ->save();

            Log::error('Direct Inventory -> Site product synchronization failed.', [
                'message'   => $exception->getMessage(),
                'exception' => $exception::class,
                'stats'     => $stats,
            ]);

            throw $exception;
        }
    }

    /**
     * @return array<int, int> Product IDs that were candidates in this cycle.
     */
    private function syncProducts( ?CarbonImmutable $cursor, CarbonImmutable $boundary, array &$stats ): array {
        $changedProductIds = [];

        $query = Product::query()
            ->where('updated_at', '<=', $boundary)
            ->orderBy('id');

        if ( $cursor !== null ) {
            $query->where('updated_at', '>', $cursor);
        }

        $query->chunkById(self::CHUNK_SIZE, function ( EloquentCollection $products ) use ( &$changedProductIds, &$stats ): void {
            if ( $products->isEmpty() ) {
                return;
            }

            $productIds = $products->pluck('id')
                ->map(static fn( $id ): int => (int) $id)
                ->values()
                ->all();

            array_push($changedProductIds, ...$productIds);

            $siteProducts = SiteProduct::query()
                ->whereIn('id', $productIds)
                ->get([ 'id', 'created_at', 'updated_at' ])
                ->keyBy('id');

            foreach ( $products as $sourceProduct ) {
                $stats['products_checked'] ++;

                /** @var SiteProduct|null $siteProduct */
                $siteProduct = $siteProducts->get((int) $sourceProduct->id);

                if ( $siteProduct !== null && !$this->sourceIsNewer($sourceProduct->updated_at, $siteProduct->updated_at) ) {
                    $stats['products_skipped'] ++;
                    continue;
                }

                $created = $siteProduct === null;
                $this->saveProduct($sourceProduct, $siteProduct);

                $stats[$created ? 'products_created' : 'products_updated'] ++;
            }
        });

        return array_values(array_unique($changedProductIds));
    }

    /**
     * Synchronizes changed variants, variants under changed products, and variants
     * whose warehouse stock changed since the previous successful cycle.
     */
    private function syncVariants( ?CarbonImmutable $cursor, CarbonImmutable $boundary, array $changedProductIds, array &$stats ): void {
        $variantIds = $this->collectVariantIdsToSync($cursor, $boundary, $changedProductIds);

        if ( $variantIds === [] ) {
            return;
        }

        foreach ( array_chunk($variantIds, self::CHUNK_SIZE) as $chunkIds ) {
            $variants = ProductVariant::query()
                ->whereIn('id', $chunkIds)
                ->orderBy('id')
                ->get();

            if ( $variants->isEmpty() ) {
                continue;
            }

            $stockRows = WarehouseStock::query()
                ->whereIn('product_variant_id', $chunkIds)
                ->groupBy('product_variant_id')
                ->select([
                    'product_variant_id',
                    DB::raw('SUM(quantity) as total_stock'),
                    DB::raw('MAX(updated_at) as stock_updated_at'),
                ])
                ->get()
                ->keyBy('product_variant_id');

            $sitePrices = SitePrice::withTrashed()
                ->whereIn('id', $chunkIds)
                ->get([ 'id', 'product_id', 'created_at', 'updated_at', 'deleted_at' ])
                ->keyBy('id');

            $variantNames = [];

            foreach ( $variants as $variant ) {
                $stats['variants_checked'] ++;

                /** @var SitePrice|null $sitePrice */
                $sitePrice = $sitePrices->get((int) $variant->id);

                $stockRow = $stockRows->get((int) $variant->id);

                $sourceTimestamp = $this->maxTimestamp($variant->updated_at, $stockRow?->stock_updated_at);

                if ( $sitePrice !== null && !$this->sourceIsNewer($sourceTimestamp, $sitePrice->updated_at) ) {
                    $stats['variants_skipped'] ++;
                    continue;
                }

                $siteProduct = SiteProduct::query()
                    ->find((int) $variant->product_id);

                // A Price cannot be safely attached if its parent product does not exist on Site.
                if ( $siteProduct === null ) {
                    $sourceProduct = Product::query()
                        ->find((int) $variant->product_id);

                    if ( $sourceProduct === null ) {
                        $stats['variants_ignored'] ++;
                        continue;
                    }

                    $siteProduct = $this->saveProduct($sourceProduct, null);
                    $stats['products_created'] ++;
                }

                $priceData = $this->makePriceData($variant, (int) ( $stockRow?->total_stock ?? 0 ), $sourceTimestamp);

                if ( $priceData === null ) {
                    $stats['variants_ignored'] ++;
                    continue;
                }

                $created   = $sitePrice === null;
                $sitePrice = $this->savePrice($siteProduct, $sitePrice, $priceData);

                $variantNames[(int) $sitePrice->id] = $priceData['variant_name'];
                $stats[$created ? 'variants_created' : 'variants_updated'] ++;
            }

            if ( $variantNames !== [] ) {
                $stats['attributes_synced'] += $this->syncVariantNameAttributes($variantNames);
            }
        }
    }

    /**
     * @return array<int, int>
     */
    private function collectVariantIdsToSync( ?CarbonImmutable $cursor, CarbonImmutable $boundary, array $changedProductIds ): array {
        // First run: synchronize every current variant.
        if ( $cursor === null ) {
            return ProductVariant::query()
                ->where('updated_at', '<=', $boundary)
                ->orderBy('id')
                ->pluck('id')
                ->map(static fn( $id ): int => (int) $id)
                ->all();
        }

        $variantIds = ProductVariant::query()
            ->where('updated_at', '>', $cursor)
            ->where('updated_at', '<=', $boundary)
            ->pluck('id');

        // When the parent product changed, include all of its variants as well.
        if ( $changedProductIds !== [] ) {
            $variantIds = $variantIds->merge(ProductVariant::query()
                ->whereIn('product_id', $changedProductIds)
                ->pluck('id'));
        }

        // Stock may change without touching product_variants.updated_at.
        $stockVariantIds = WarehouseStock::query()
            ->whereNotNull('product_variant_id')
            ->where('updated_at', '>', $cursor)
            ->where('updated_at', '<=', $boundary)
            ->pluck('product_variant_id');

        return $variantIds->merge($stockVariantIds)
            ->filter(static fn( $id ): bool => is_numeric($id) && (int) $id > 0)
            ->map(static fn( $id ): int => (int) $id)
            ->unique()
            ->sort()
            ->values()
            ->all();
    }

    private function saveProduct( Product $source, ?SiteProduct $siteProduct ): SiteProduct {
        $siteProduct ??= new SiteProduct();
        $isNew       = !$siteProduct->exists;

        $slug = Str::slug((string) $source->name);

        if ( $slug === '' ) {
            $slug = 'product-' . $source->id;
        }

        $productCode = filled($source->code) ? trim((string) $source->code) : SiteProduct::generateInventoryProductCode((int) $source->id);

        $siteProduct->timestamps = false;
        $siteProduct->forceFill([
            'id'                         => (int) $source->id,
            'title'                      => trim((string) $source->name),
            'product_code'               => $productCode,
            'published'                  => (bool) ( $source->is_sellable ?? true ),
            'type'                       => 'physical',
            'price_type'                 => 'multiple-price',
            'slug'                       => $slug . '-' . $source->id,
            'lang'                       => app()->getLocale(),
            'inventory_to_site_synced'   => true,
            'site_to_inventory_verified' => false,
            'created_at'                 => $isNew ? ( $source->created_at ?? now() ) : $siteProduct->created_at,
            'updated_at'                 => $source->updated_at ?? now(),
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
                'created_at' => $isNew ? now() : $sitePrice->created_at,
            ]);
        $sitePrice->product()
            ->associate($siteProduct);
        $sitePrice->save();

        if ( $sitePrice->trashed() ) {
            // restore() normally touches updated_at; restore quietly and then restore source timestamp.
            $sitePrice->restore();
            $sitePrice->timestamps = false;
            $sitePrice->forceFill([
                'updated_at' => $data['updated_at'],
            ])
                ->save();
        }

        return $sitePrice;
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
     * @param array<int, string|null> $variantNames [price_id => variant_name]
     */
    private function syncVariantNameAttributes( array $variantNames ): int {
        $names = collect($variantNames)
            ->filter(static fn( $name ): bool => is_string($name) && trim($name) !== '')
            ->map(static fn( string $name ): string => trim($name))
            ->unique()
            ->values();

        if ( $names->isEmpty() ) {
            return 0;
        }

        $connection = DB::connection('site');
        $now        = now()->toDateTimeString();

        return $connection->transaction(function () use ( $connection, $variantNames, $names, $now ): int {
            $attributeGroup = $connection->table('attribute_groups')
                ->where('name', 'مدل')
                ->where('lang', app()->getLocale())
                ->first();

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

                if ( Schema::connection('site')
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

            $groupAttributeIds = $connection->table('attributes')
                ->where('attribute_group_id', $groupId)
                ->pluck('id')
                ->map(static fn( $id ): int => (int) $id)
                ->all();

            $synced = 0;

            foreach ( $variantNames as $priceId => $name ) {
                $priceExists = $connection->table('prices')
                    ->where('id', (int) $priceId)
                    ->exists();

                if ( !$priceExists ) {
                    continue;
                }

                if ( $groupAttributeIds !== [] ) {
                    $connection->table('attribute_price')
                        ->where('price_id', (int) $priceId)
                        ->whereIn('attribute_id', $groupAttributeIds)
                        ->delete();
                }

                $normalizedName = is_string($name) ? trim($name) : '';

                if ( $normalizedName !== '' && $attributesByName->has($normalizedName) ) {
                    $connection->table('attribute_price')
                        ->insert([
                            'attribute_id' => (int) $attributesByName->get($normalizedName)->id,
                            'price_id'     => (int) $priceId,
                            'created_at'   => $now,
                            'updated_at'   => $now,
                        ]);
                }

                $synced ++;
            }

            return $synced;
        });
    }

    private function sourceIsNewer( mixed $sourceUpdatedAt, mixed $siteUpdatedAt ): bool {
        if ( $sourceUpdatedAt === null ) {
            return false;
        }

        if ( $siteUpdatedAt === null ) {
            return true;
        }

        return CarbonImmutable::parse($sourceUpdatedAt)
            ->greaterThan(CarbonImmutable::parse($siteUpdatedAt));
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
