<?php

use App\Models\Category;
use App\Models\PreinvoiceOrder;
use App\Models\PreinvoiceOrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Models\WarehouseStock;
use App\Services\WarehouseStockService;

function largePayloadUser(): User
{
    return User::factory()->create();
}

function largePayloadProducts(int $count): array
{
    $category = Category::query()->create(['name' => 'Large payload '.uniqid()]);
    $rows = [];

    for ($i = 1; $i <= $count; $i++) {
        $product = Product::withoutEvents(fn () => Product::query()->create([
            'category_id' => $category->id,
            'name' => 'Large product '.$i,
            'sku' => 'LP-'.uniqid().'-'.$i,
            'code' => (string) (8000 + $i),
            'stock' => 1000,
            'reserved' => 0,
            'price' => 1000 + $i,
            'is_sellable' => true,
        ]));
        $variant = ProductVariant::query()->create([
            'product_id' => $product->id,
            'is_active' => true,
            'sales_enabled' => true,
            'variant_name' => 'Default '.$i,
            'variant_code' => 'LPV-'.uniqid().'-'.$i,
            'sell_price' => 1000 + $i,
            'stock' => 1000,
            'reserved' => 0,
        ]);
        WarehouseStock::query()->create([
            'warehouse_id' => WarehouseStockService::centralWarehouseId(),
            'product_id' => $product->id,
            'product_variant_id' => $variant->id,
            'quantity' => 1000,
        ]);
        $rows[] = [
            'item_id' => null,
            'id' => $product->id,
            'product_id' => $product->id,
            'variety_id' => $variant->id,
            'variant_id' => $variant->id,
            'quantity' => 2,
            'price' => 1000 + $i,
            'line_discount_amount' => 0,
        ];
    }

    return $rows;
}

function largePayloadPost(array $rows, array $overrides = []): array
{
    return array_merge([
        'intent' => 'submit',
        'customer_name' => 'مشتری بزرگ',
        'customer_mobile' => '09120000000',
        'is_in_person' => 0,
        'discount_amount' => 0,
        'products_payload' => json_encode($rows, JSON_THROW_ON_ERROR),
        'products_payload_count' => count($rows),
        'products_payload_version' => 1,
        'products_payload_complete' => 1,
        'products_payload_total_quantity' => collect($rows)->sum('quantity'),
        'products_payload_gross_total' => collect($rows)->sum(fn ($row) => (int) $row['quantity'] * (int) $row['price']),
    ], $overrides);
}

function largePayloadOrder(User $user, array $rows): PreinvoiceOrder
{
    $order = PreinvoiceOrder::query()->create([
        'uuid' => 'LPO-'.uniqid(),
        'created_by' => $user->id,
        'status' => PreinvoiceOrder::STATUS_DRAFT,
        'customer_name' => 'مشتری بزرگ',
        'customer_mobile' => '09120000000',
        'total_price' => collect($rows)->sum(fn ($row) => (int) $row['quantity'] * (int) $row['price']),
    ]);

    foreach ($rows as $index => $row) {
        $item = PreinvoiceOrderItem::query()->create([
            'preinvoice_order_id' => $order->id,
            'product_id' => $row['id'],
            'variant_id' => $row['variety_id'],
            'quantity' => $row['quantity'],
            'price' => $row['price'],
            'line_total' => $row['quantity'] * $row['price'],
            'sort_order' => $index + 1,
        ]);
        $rows[$index]['item_id'] = $item->id;
    }

    return $order->fresh('items');
}
