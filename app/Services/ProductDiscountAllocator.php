<?php

namespace App\Services;

use App\Support\SalesDocumentTotals;
use App\Support\DiscountMath;
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
            $gross = SalesDocumentTotals::calculate($productItems)['subtotal_before_discount'];
            $input = $byProduct[$productId] ?? null;
            $amount = 0;

            if ($input) $amount = DiscountMath::amount($gross, $input['type'], $input['value']);
            $weights = $productItems->mapWithKeys(fn ($item) => [(int)$item->id => SalesDocumentTotals::lineSubtotal($item)])->all();
            foreach (DiscountMath::allocate($weights, $amount) as $id => $share) $lines[$id] = $share;

            foreach ($productItems as $item) {
                $lines[(int) $item->id] = $lines[(int) $item->id] ?? 0;
            }

            if ($input || $amount > 0) {
                $actual = (int) $productItems->sum(fn ($item) => $lines[(int) $item->id] ?? 0);
                $groups[] = [
                    'product_id' => $productId,
                    'discount_type' => $input['type'] ?? 'amount',
                    'discount_value' => $input['value'] ?? 0,
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
            if (isset($byProduct[$productId])) {
                throw \Illuminate\Validation\ValidationException::withMessages(['product_discounts' => 'تخفیف یک کالا بیش از یک بار ارسال شده است. صفحه را دوباره باز کنید.']);
            }
            $type = (string) ($input['type'] ?? $input['discount_type'] ?? 'amount');
            $type = in_array($type, ['amount', 'percent'], true) ? $type : 'amount';
            $value = DiscountMath::value($type, $input['value'] ?? $input['discount_value'] ?? 0);
            $byProduct[$productId] = ['type' => $type, 'value' => $value];
        }

        return $byProduct;
    }
}
