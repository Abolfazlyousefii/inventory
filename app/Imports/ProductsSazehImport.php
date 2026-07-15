<?php

namespace App\Imports;

use App\Models\Product;
use Illuminate\Support\Collection;
use Maatwebsite\Excel\Concerns\ToCollection;
use Maatwebsite\Excel\Concerns\WithHeadingRow;
use Maatwebsite\Excel\Imports\HeadingRowFormatter;

HeadingRowFormatter::default('none'); // تیترها دقیقاً فارسی باقی بمانند

class ProductsSazehImport implements ToCollection, WithHeadingRow
{
    public function collection(Collection $rows)
    {
        foreach ($rows as $row) {
            // تیترهای سازه حساب (دقیقاً همین‌ها)
            $code = trim((string)($row['كد'] ?? ''));
            $name = trim((string)($row['نام كالا'] ?? ''));

            if ($name === '') continue; // اگر نام کالا خالی بود، رد کن

            // قیمت‌ها: ممکنه تو اکسل با کاما بیاد
            $saleRetail = $this->money($row['ف جزئي'] ?? null);
            $saleWholesale = $this->money($row['ف كلي'] ?? null);
            $buyRetail = $this->money($row['خ جزئي'] ?? null);
            $buyWholesale = $this->money($row['خ كلي'] ?? null);

            // آپسرت: اگر code موجود بود با code آپدیت کن، اگر نبود با نام
            $key = $code !== '' ? ['code' => $code] : ['name' => $name];
            $product = Product::firstOrNew($key);

            $payload = [
                'name' => $name,
                'code' => $code !== '' ? $code : null,
                'short_barcode' => $this->clean($row['باركد/اختصاري'] ?? null),
                'unit' => $this->clean($row['واحد'] ?? null),
                'barcode' => $this->clean($row['بارکد'] ?? null),
                'color' => $this->clean($row['رنگ'] ?? null),
            ];

            if (! $product->exists) {
                $payload['stock'] = 0;
                $payload['reserved'] = 0;
            }

            foreach ([
                'sale_retail' => $saleRetail,
                'sale_wholesale' => $saleWholesale,
                'buy_retail' => $buyRetail,
                'buy_wholesale' => $buyWholesale,
            ] as $field => $value) {
                if ($value !== null && $value > 0) {
                    $payload[$field] = $value;
                }
            }

            if ($saleRetail !== null && $saleRetail > 0) {
                $payload['price'] = $saleRetail;
            }

            $product->fill($payload)->save();
        }
    }

    private function clean($v): ?string
    {
        $s = trim((string)$v);
        return $s === '' ? null : $s;
    }

    private function money($v): ?int
    {
        if ($v === null) return null;
        $s = preg_replace('/[^\d]/', '', (string)$v);
        return $s === '' ? null : (int)$s;
    }
}
