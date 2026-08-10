<?php

namespace App\Services;

use Illuminate\Validation\ValidationException;

class SalesReturnItemsPayloadDecoder
{
    public const MAX_BYTES = 8 * 1024 * 1024;

    public function decode(string $json): array
    {
        if ($json === '' || strlen($json) > self::MAX_BYTES) {
            $this->fail();
        }

        try {
            $payload = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            $this->fail();
        }

        return $this->expand($payload);
    }

    public function expand(mixed $payload): array
    {
        if (! is_array($payload)
            || ($payload['version'] ?? null) !== 1
            || ! is_array($payload['items'] ?? null)
            || ! is_array($payload['new_products'] ?? null)
            || count($payload['items']) > 1000) {
            $this->fail();
        }

        $products = $payload['new_products'];
        $variantMaps = [];

        foreach ($products as $productUuid => $product) {
            if (! is_string($productUuid) || trim($productUuid) === '' || ! is_array($product)) {
                $this->fail('اطلاعات کالای جدید ناقص است.');
            }
            if ((string) ($product['temporary_product_uuid'] ?? '') !== $productUuid) {
                $this->fail('اطلاعات تکراری یا ناسازگار برای کالای جدید ارسال شده است.');
            }

            $variants = $product['selected_variants'] ?? null;
            if (! is_array($variants) || $variants === []) {
                $this->fail('اطلاعات کالای جدید ناقص است.');
            }

            foreach ($variants as $variant) {
                $variantUuid = is_array($variant) ? trim((string) ($variant['temporary_variant_uuid'] ?? '')) : '';
                if ($variantUuid === '' || isset($variantMaps[$productUuid][$variantUuid])) {
                    $this->fail('اطلاعات تکراری یا ناسازگار برای کالای جدید ارسال شده است.');
                }
                $variantMaps[$productUuid][$variantUuid] = $variant;
            }
        }

        return collect($payload['items'])->map(function ($item) use ($products, $variantMaps) {
            if (! is_array($item)) {
                $this->fail();
            }
            if (($item['item_source'] ?? null) !== 'new_product') {
                unset($item['new_product_ref']);
                return $item;
            }

            $reference = $item['new_product_ref'] ?? null;
            $productUuid = is_array($reference) ? trim((string) ($reference['temporary_product_uuid'] ?? '')) : '';
            $variantUuid = is_array($reference) ? trim((string) ($reference['temporary_variant_uuid'] ?? '')) : '';
            $product = $products[$productUuid] ?? null;
            $variant = $variantMaps[$productUuid][$variantUuid] ?? null;

            if (! is_array($product)) {
                $this->fail('اطلاعات کالای جدید ناقص است.');
            }
            if (! is_array($variant)) {
                $this->fail('یکی از تنوع‌های انتخاب‌شده قابل شناسایی نیست.');
            }

            unset($item['new_product_ref']);
            $item['new_product_payload'] = array_replace($product, [
                'variant_name' => (string) ($variant['display_name'] ?? ''),
                'temporary_variant_uuid' => $variantUuid,
                'model_list_id' => $variant['model_list_id'] ?? null,
                'design_index' => $variant['design_index'] ?? 0,
                'selected_variants' => [$variant],
            ]);

            return $item;
        })->values()->all();
    }

    private function fail(string $message = 'اطلاعات اقلام برگشتی قابل پردازش نیست. صفحه را تازه‌سازی کنید و دوباره تلاش کنید.'): never
    {
        throw ValidationException::withMessages(['items_payload' => $message]);
    }
}
