<?php

namespace App\Services;

class SalesDiscountAllocationService
{
    public const MODE_ALLOCATED_LINES = 'allocated_lines';

    public function allocate(array $rows, array $discountInput): array
    {
        $normalizedRows = [];
        foreach ($rows as $index => $row) {
            $key = (string) ($row['key'] ?? $index);
            $quantity = max((int) ($row['quantity'] ?? 0), 0);
            $price = max((int) ($row['price'] ?? 0), 0);
            $gross = $quantity * $price;
            $normalizedRows[$key] = [
                'key' => $key,
                'product_id' => (int) ($row['product_id'] ?? 0),
                'variant_id' => isset($row['variant_id']) ? (int) $row['variant_id'] : null,
                'quantity' => $quantity,
                'price' => $price,
                'gross' => $gross,
            ];
        }

        $subtotal = array_sum(array_column($normalizedRows, 'gross'));
        $lines = [];
        foreach ($normalizedRows as $key => $row) {
            $lines[$key] = [
                'gross' => (int) $row['gross'],
                'product_discount' => 0,
                'invoice_discount' => 0,
                'line_discount_amount' => 0,
                'line_total' => (int) $row['gross'],
            ];
        }

        $productsInput = $this->normalizeProductDiscounts($discountInput['products'] ?? []);
        $productBreakdown = [];
        $productDiscountTotal = 0;

        $rowsByProduct = [];
        foreach ($normalizedRows as $key => $row) {
            if ($row['product_id'] > 0) {
                $rowsByProduct[$row['product_id']][$key] = $row;
            }
        }

        foreach ($rowsByProduct as $productId => $productRows) {
            $input = $productsInput[$productId] ?? ['type' => 'amount', 'value' => 0];
            $groupGross = array_sum(array_column($productRows, 'gross'));
            $discountAmount = $this->discountAmount($groupGross, $input['type'], $input['value']);
            $productWeights = [];
            foreach ($productRows as $key => $row) {
                $productWeights[$key] = (int) $row['gross'];
            }
            $allocations = $this->allocateProportionally($productWeights, $discountAmount);

            foreach ($allocations as $key => $amount) {
                $lines[$key]['product_discount'] = (int) $amount;
            }

            $productDiscountTotal += $discountAmount;
            if ($discountAmount > 0 || ($input['value'] ?? 0) > 0) {
                $productBreakdown[(string) $productId] = [
                    'product_id' => (int) $productId,
                    'discount_type' => $input['type'],
                    'discount_value' => (int) $input['value'],
                    'discount_amount' => (int) $discountAmount,
                    'raw_subtotal' => (int) $groupGross,
                    'final_amount' => max((int) $groupGross - (int) $discountAmount, 0),
                ];
            }
        }

        $subtotalAfterProduct = max($subtotal - $productDiscountTotal, 0);
        $invoiceInput = $this->normalizeDiscount($discountInput['invoice'] ?? []);
        $invoiceDiscount = $this->discountAmount($subtotalAfterProduct, $invoiceInput['type'], $invoiceInput['value']);

        $invoiceWeights = [];
        foreach ($normalizedRows as $key => $row) {
            $invoiceWeights[$key] = max((int) $row['gross'] - (int) $lines[$key]['product_discount'], 0);
        }
        $invoiceAllocations = $this->allocateProportionally($invoiceWeights, $invoiceDiscount);
        foreach ($invoiceAllocations as $key => $amount) {
            $lines[$key]['invoice_discount'] = (int) $amount;
        }

        $lineDiscountTotal = 0;
        foreach ($lines as $key => $line) {
            $lineDiscount = min((int) $line['gross'], (int) $line['product_discount'] + (int) $line['invoice_discount']);
            $lines[$key]['line_discount_amount'] = $lineDiscount;
            $lines[$key]['line_total'] = max((int) $line['gross'] - $lineDiscount, 0);
            $lineDiscountTotal += $lineDiscount;
        }

        return [
            'subtotal' => (int) $subtotal,
            'product_discount_amount' => (int) $productDiscountTotal,
            'invoice_discount_amount' => (int) $invoiceDiscount,
            'total_discount_amount' => (int) $lineDiscountTotal,
            'lines' => $lines,
            'breakdown' => [
                'products' => $productBreakdown,
                'invoice' => [
                    'discount_type' => $invoiceInput['type'],
                    'discount_value' => (int) $invoiceInput['value'],
                    'discount_amount' => (int) $invoiceDiscount,
                    'base_amount' => (int) $subtotalAfterProduct,
                ],
                'subtotal' => (int) $subtotal,
                'product_discount_amount' => (int) $productDiscountTotal,
                'invoice_discount_amount' => (int) $invoiceDiscount,
                'total_discount_amount' => (int) $lineDiscountTotal,
                'allocation_mode' => self::MODE_ALLOCATED_LINES,
            ],
        ];
    }

    private function normalizeProductDiscounts(array $products): array
    {
        $normalized = [];
        foreach ($products as $productId => $discount) {
            if (is_array($discount) && isset($discount['product_id'])) {
                $productId = $discount['product_id'];
            }
            $productId = (int) $productId;
            if ($productId <= 0 || ! is_array($discount)) {
                continue;
            }
            $normalized[$productId] = $this->normalizeDiscount($discount);
        }

        return $normalized;
    }

    private function normalizeDiscount(array $discount): array
    {
        $type = (string) ($discount['type'] ?? $discount['discount_type'] ?? 'amount');
        if (! in_array($type, ['amount', 'percent'], true)) {
            $type = 'amount';
        }
        $value = max((int) floor((float) ($discount['value'] ?? $discount['discount_value'] ?? 0)), 0);
        if ($type === 'percent') {
            $value = min($value, 100);
        }

        return ['type' => $type, 'value' => $value];
    }

    private function discountAmount(int $base, string $type, int $value): int
    {
        $base = max($base, 0);
        $value = max($value, 0);
        if ($base <= 0 || $value <= 0) {
            return 0;
        }

        $amount = $type === 'percent' ? (int) floor($base * min($value, 100) / 100) : $value;

        return min($amount, $base);
    }

    private function allocateProportionally(array $weights, int $amount): array
    {
        $amount = max($amount, 0);
        $allocations = array_fill_keys(array_keys($weights), 0);
        $positiveWeights = array_filter($weights, fn ($weight) => (int) $weight > 0);
        $totalWeight = array_sum($positiveWeights);
        if ($amount <= 0 || $totalWeight <= 0 || empty($positiveWeights)) {
            return $allocations;
        }

        $allocated = 0;
        foreach ($positiveWeights as $key => $weight) {
            $share = (int) floor($amount * (int) $weight / $totalWeight);
            $allocations[$key] = $share;
            $allocated += $share;
        }

        $remainder = $amount - $allocated;
        if ($remainder > 0) {
            $targetKey = array_key_last($positiveWeights);
            $maxWeight = max($positiveWeights);
            foreach ($positiveWeights as $key => $weight) {
                if ((int) $weight === (int) $maxWeight) {
                    $targetKey = $key;
                }
            }
            $allocations[$targetKey] += $remainder;
        }

        return $allocations;
    }
}
