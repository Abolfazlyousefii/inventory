<?php
namespace App\Observers;
use App\Models\ProductVariant; use App\Services\Integrations\CatalogIntegrationPublisher;
class ProductVariantObserver { public bool $afterCommit=true; public function updated(ProductVariant $v): void { if($v->wasChanged('stock')) app(CatalogIntegrationPublisher::class)->publishVariant($v->fresh(),'catalog.variant.stock_changed'); if($v->wasChanged('sell_price')) app(CatalogIntegrationPublisher::class)->publishVariant($v->fresh(),'catalog.variant.price_changed'); if($v->wasChanged(['is_active','sales_enabled'])) app(CatalogIntegrationPublisher::class)->publishVariant($v->fresh(),'catalog.variant.updated'); } }
