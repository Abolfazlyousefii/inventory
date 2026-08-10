<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;
use App\Imports\ProductsSazehImport;
use Symfony\Component\HttpFoundation\StreamedResponse;

class ProductImportController extends Controller
{
    public function show()
    {
        return view('products.import');
    }

    public function import(Request $request)
    {
        $request->validate([
            'file' => ['required','file','mimes:xlsx,xls,csv','max:10240'],
        ]);

        Excel::import(new ProductsSazehImport, $request->file('file'));

        return redirect()
            ->route('products.index')
            ->with('success', 'ایمپورت محصولات (سازه حساب) انجام شد ✅');
    }

    public function template(): StreamedResponse
    {
        // تیترهای سازه حساب (دقیقاً همین‌ها)
        $headers = [
            'كد',
            'باركد/اختصاري',
            'نام كالا',
            'موجودي',
            'واحد',
            'ف جزئي',
            'ف كلي',
            'خ جزئي',
            'خ كلي',
            'رزرو',
            'بارکد',
            'رنگ',
        ];

        // چند ردیف نمونه
        $rows = [
            ['1001','KB-1001','کابل شارژ',10,'عدد',189000,175000,150000,140000,0,'6261234567890','مشکی'],
            ['1004','GR-1004','گارد سیلیکون',50,'عدد',99000,90000,70000,65000,5,'6269876543210','سفید'],
        ];

        $callback = function () use ($headers, $rows) {
            // UTF-8 BOM برای اینکه اکسل فارسی رو درست باز کنه
            echo "\xEF\xBB\xBF";

            $out = fopen('php://output', 'w');
            fputcsv($out, $headers);

            foreach ($rows as $r) {
                fputcsv($out, $r);
            }

            fclose($out);
        };

        return response()->streamDownload($callback, 'sazeh_products_template.csv', [
            'Content-Type' => 'text/csv; charset=UTF-8',
        ]);
    }
}
