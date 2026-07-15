<?php

use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\PreinvoiceDraftReservation;
use App\Models\PreinvoiceOrder;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Services\PreinvoiceReservationService;
use App\Services\SalesReturnCalculationService;

it('keeps critical financial and inventory models guarded by expected relationships and casts', function (): void {
    expect((new Invoice())->getFillable())->toContain('uuid', 'discount_amount', 'subtotal', 'total', 'status')
        ->and((new InvoiceItem())->getFillable())->toContain('quantity', 'price', 'line_total', 'line_discount_amount')
        ->and((new Product())->getCasts())->toHaveKeys(['stock', 'reserved', 'price', 'is_sellable'])
        ->and((new ProductVariant())->getCasts())->toHaveKeys(['stock', 'reserved', 'sell_price', 'is_active', 'sales_enabled'])
        ->and((new PreinvoiceDraftReservation())->getCasts())->toHaveKeys(['quantity', 'expires_at', 'converted_at', 'released_at', 'reservation_scope'])
        ->and((new PreinvoiceOrder())->getCasts())->toHaveKeys(['discount_amount', 'total_price', 'stock_frozen_until', 'stock_released_at']);
});

it('keeps critical workflow services exposing the business methods that route tests depend on', function (): void {
    expect(method_exists(PreinvoiceReservationService::class, 'releaseReservation'))->toBeTrue()
        ->and(method_exists(PreinvoiceReservationService::class, 'releaseOfficialReservationsForOrder'))->toBeTrue()
        ->and(method_exists(PreinvoiceReservationService::class, 'consumeOfficialReservationsForOrder'))->toBeTrue()
        ->and(method_exists(PreinvoiceReservationService::class, 'assertFinanceApprovable'))->toBeTrue()
        ->and(method_exists(SalesReturnCalculationService::class, 'allocateInvoiceDiscount'))->toBeTrue()
        ->and(method_exists(SalesReturnCalculationService::class, 'calculateInternalPreview'))->toBeTrue();
});
