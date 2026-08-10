<?php

namespace App\Services;

use App\Support\SalesDocumentTotals;
use Illuminate\Support\Collection;

class ProductDiscountAllocator
{
    /**
     * @param iterable<object> $items
     * @param array<int,array<string,mixed>> $inputs
     * @return array{lines: array<int,int>, groups: array<int,array<string,mixed>>}
     */
    public function allocate(iterable $items, array $inputs): array
    {
        $rows = $items instanceof Collection ? $items : collect($items);
        $byProduct = $this->normalizeInputs($inputs);
        $lines = [];
        $groups = [];

        foreach ($rows->groupBy('product_id') as $productId => $productItems) {
            $productId = (int) $productId;
            $gross = (int) $productItems->sum(fn ($item) => SalesDocumentTotals::lineSubtotal($item));
            $input = $byProduct[$productId] ?? null;
            $amount = 0;

            if ($input) {
                $amount = $input['type'] === 'percent'
                    ? (int) floor($gross * $input['value'] / 100)
                    : (int) $input['value'];
                $amount = min(max($amount, 0), $gross);
            }

            $positive = $productItems->filter(fn ($item) => SalesDocumentTotals::lineSubtotal($item) > 0)->values();
            $allocated = 0;
            foreach ($positive as $index => $item) {
                $lineGross = SalesDocumentTotals::lineSubtotal($item);
                $share = $index === $positive->count() - 1
                    ? $amount - $allocated
                    : (int) floor($amount * $lineGross / max($gross, 1));
                $share = min(max($share, 0), $lineGross);
                $lines[(int) $item->id] = $share;
                $allocated += $share;
            }

            foreach ($productItems as $item) {
                $lines[(int) $item->id] = $lines[(int) $item->id] ?? 0;
            }

            if ($input || $amount > 0) {
                $actual = (int) $productItems->sum(fn ($item) => $lines[(int) $item->id] ?? 0);
                $groups[] = [
                    'product_id' => $productId,
                    'discount_type' => $input['type'] ?? 'amount',
                    'discount_value' => (int) ($input['value'] ?? 0),
                    'discount_amount' => $actual,
                    'raw_subtotal' => $gross,
                    'final_amount' => max($gross - $actual, 0),
                ];
            }
        }

        return ['lines' => $lines, 'groups' => $groups];
    }

    private function normalizeInputs(array $inputs): array
    {
        $byProduct = [];
        foreach ($inputs as $input) {
            if (! is_array($input)) continue;
            $productId = (int) ($input['product_id'] ?? 0);
            if ($productId <= 0) continue;
            $type = (string) ($input['type'] ?? $input['discount_type'] ?? 'amount');
            $type = in_array($type, ['amount', 'percent'], true) ? $type : 'amount';
            $value = max((int) ($input['value'] ?? $input['discount_value'] ?? 0), 0);
            $byProduct[$productId] = ['type' => $type, 'value' => $type === 'percent' ? min($value, 100) : $value];
        }

        return $byProduct;
    }
}
