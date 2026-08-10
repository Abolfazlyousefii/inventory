<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;

class ProductRowsExport implements FromArray, ShouldAutoSize, WithHeadings, WithMapping
{
    public function __construct(private readonly array $rows)
    {
    }

    public function array(): array
    {
        return $this->rows;
    }

    public function headings(): array
    {
        return [
            'لینک تصویر',
            'نام محصول',
            'کد محصول / SKU',
            'دسته‌بندی',
            'موجودی فعلی',
            'قیمت فروش (تومان)',
            'وضعیت موجودی',
            'تاریخ آخرین بروزرسانی',
            'واحد',
            'بارکد',
        ];
    }

    public function map($row): array
    {
        return [
            $row['image_url'],
            $row['name'],
            $row['sku'],
            $row['category'],
            $row['stock'],
            $row['price'],
            $row['stock_status'],
            $row['updated_at'],
            $row['unit'],
            $row['barcode'],
        ];
    }
}
