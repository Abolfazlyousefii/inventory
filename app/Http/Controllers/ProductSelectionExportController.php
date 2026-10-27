<?php

namespace App\Http\Controllers;

use App\Services\ProductSelectionPdfService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ProductSelectionExportController extends Controller
{
    public function __invoke(Request $request, ProductSelectionPdfService $pdf): Response
    {
        abort_unless($request->user()?->hasPermission('products.export'), 403);

        $data = $request->validate([
            'product_ids' => ['required', 'array', 'min:1', 'max:5'],
            'product_ids.*' => ['required', 'integer', 'distinct', 'exists:products,id'],
        ]);

        // Keep image-heavy mPDF strictly bounded. Larger selections are exported
        // via the paginated, spreadsheet-friendly stock rows endpoint.
        $activeVariants = DB::table('product_variants')
            ->whereIn('product_id', $data['product_ids'])
            ->where('is_active', 1)->count();
        if ($activeVariants > 120) {
            throw ValidationException::withMessages([
                'product_ids' => 'این انتخاب برای PDF بیش از حد بزرگ است. از خروجی ریز موجودی Excel استفاده کنید.',
            ]);
        }

        $content = $pdf->render(array_map('intval', $data['product_ids']));
        $filename = 'ariya-products-'.now()->format('Ymd-His').'.pdf';

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
