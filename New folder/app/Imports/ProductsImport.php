<?php

namespace App\Imports;

use App\Models\Product;
use App\Models\Category;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;

class ProductsImport implements ToCollection, WithHeadingRow
{
    public function collection(Collection $rows)
    {
        foreach ($rows as $row) {
            $name = trim((string)($row['name'] ?? ''));
            $sku  = trim((string)($row['sku'] ?? ''));
            $categoryName = trim((string)($row['category'] ?? ''));

            if ($name === '' || $sku === '' || $categoryName === '') {
                continue; // یا می‌تونی خطا جمع کنی
            }

            $category = Category::firstOrCreate(['name' => $categoryName]);
            $price = (int) preg_replace('/[^\d]/', '', (string)($row['price'] ?? 0));

            $product = Product::firstOrNew(['sku' => $sku]);
            $payload = [
                'name' => $name,
                'category_id' => $category->id,
            ];

            if (! $product->exists) {
                $payload['stock'] = 0;
            }

            if ($price > 0) {
                $payload['price'] = $price;
            }

            $product->fill($payload)->save();
        }
    }
}
