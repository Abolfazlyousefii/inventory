<?php

namespace App\Http\Controllers;

use App\Services\ProductSelectionPdfService;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class ProductSelectionExportController extends Controller
{
    public function __invoke(Request $request, ProductSelectionPdfService $pdf): Response
    {
        abort_unless($request->user()?->hasPermission('products.export'), 403);

        $data = $request->validate([
            'product_ids' => ['required', 'array', 'min:1', 'max:200'],
            'product_ids.*' => ['required', 'integer', 'distinct', 'exists:products,id'],
        ]);

        $content = $pdf->render(array_map('intval', $data['product_ids']));
        $filename = 'ariya-products-'.now()->format('Ymd-His').'.pdf';

        return response($content, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
            'Cache-Control' => 'private, no-store',
        ]);
    }
}
