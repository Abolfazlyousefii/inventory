<?php

namespace App\Http\Controllers;

use App\Models\Category;
use App\Models\ModelList;
use App\Models\Warehouse;
use App\Services\ProductExportService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Mpdf\Mpdf;
use Mpdf\Output\Destination;
use RuntimeException;
use Throwable;

class ProductExportController extends Controller
{
    private const MAX_PDF_PRODUCTS = 300;
    private const MAX_PDF_VARIANTS = 1500;

    public function __construct(
        private readonly ProductExportService $service
    ) {
    }

    public function index(Request $request)
    {
        $filters = $this->validatedFilters($request);

        $categories = Category::query()
            ->orderBy('name')
            ->get();

        $warehouses = Warehouse::query()
            ->orderBy('name')
            ->get();

        $modelListsByBrand = $this->modelListsByBrand($filters);

        $rows = collect($this->service->rows($filters));

        return view('product-exports.index', compact(
            'categories',
            'warehouses',
            'modelListsByBrand',
            'filters',
            'rows'
        ));
    }

    public function filter(Request $request): string
    {
        $filters = $this->validatedFilters($request);
        $rows = collect($this->service->rows($filters));

        return view('product-exports.partials.table', compact('rows'))
            ->render();
    }

    public function export(Request $request)
    {
        $filters = $this->validatedFilters($request, true);

        try {
            $stats = $this->service->exportStats(
                $filters,
                self::MAX_PDF_PRODUCTS,
                self::MAX_PDF_VARIANTS
            );

            if ($this->pdfExportIsTooLarge($stats)) {
                return redirect()
                    ->route('admin.product-exports.index', $this->redirectFilters($filters))
                    ->with('error', $this->largeExportMessage($stats));
            }

            $this->preparePdfEnvironment();
            $this->prepareLongRunningExport();

            $mpdf = $this->createMpdf();

            $meta = $this->service->meta($filters);
            $skipImages = true;

            $mpdf->SetHTMLFooter(
                $this->pdfFooter($meta)
            );

            $mpdf->WriteHTML(view('product-exports.pdf-start', [
                'meta' => $meta,
                'styles' => $this->pdfStyles(),
                'skipImages' => $skipImages,
            ])->render());

            $buffer = [];

            $rowCount = $this->service->eachRow(
                $filters,
                function (array $row, int $number) use (
                    &$buffer,
                    $mpdf,
                    $skipImages
                ) {
                    $buffer[] = [
                        'row' => $row,
                        'number' => $number,
                    ];

                    if (count($buffer) >= 50) {
                        $mpdf->WriteHTML(view('product-exports.partials.pdf-rows', [
                            'rows' => $buffer,
                            'skipImages' => $skipImages,
                        ])->render());

                        $buffer = [];
                    }
                }
            );

            if (! empty($buffer)) {
                $mpdf->WriteHTML(view('product-exports.partials.pdf-rows', [
                    'rows' => $buffer,
                    'skipImages' => $skipImages,
                ])->render());
            }

            if ($rowCount === 0) {
                return back()->with(
                    'error',
                    'محصولی مطابق فیلترهای انتخاب‌شده پیدا نشد.'
                );
            }

            $mpdf->WriteHTML(view('product-exports.pdf-end')->render());

            $filename = 'product-report-'
                . now()->format('Ymd-His')
                . '.pdf';
            $pdfPath = storage_path('app/mpdf-temp/' . $filename);

            $mpdf->Output($pdfPath, Destination::FILE);

            $handle = fopen($pdfPath, 'rb');
            $header = is_resource($handle) ? fread($handle, 5) : false;

            if (is_resource($handle)) {
                fclose($handle);
            }

            if ($header !== '%PDF-') {
                throw new RuntimeException(
                    'فایل PDF معتبر تولید نشد.'
                );
            }

            return response()
                ->download($pdfPath, $filename, [
                    'Content-Type' => 'application/pdf',
                    'Cache-Control' =>
                        'private, no-store, no-cache, must-revalidate',
                    'Pragma' => 'no-cache',
                ])
                ->deleteFileAfterSend(true);
        } catch (Throwable $exception) {
            $context = [
                'filters' => $request->all(),
                'normalized_filters' => $filters,
                'user_id' => auth()->id(),
                'message' => $exception->getMessage(),
                'exception' => get_class($exception),
                'file' => $exception->getFile(),
                'line' => $exception->getLine(),
            ];

            if (app()->environment('local')) {
                $context['trace'] = $exception->getTraceAsString();
            }

            Log::error('Product export PDF failed', $context);

            return redirect()
                ->route('admin.product-exports.index', $this->redirectFilters($filters))
                ->with(
                    'error',
                    'خطا در ساخت PDF رخ داد. لطفاً چند لحظه بعد دوباره تلاش کنید یا فیلترها را محدودتر کنید.'
                );
        }
    }

    private function pdfExportIsTooLarge(array $stats): bool
    {
        return ($stats['products_count'] ?? 0) > self::MAX_PDF_PRODUCTS
            || ($stats['variants_count'] ?? 0) > self::MAX_PDF_VARIANTS;
    }

    private function largeExportMessage(array $stats): string
    {
        return sprintf(
            'تعداد نتایج برای ساخت PDF زیاد است: %s محصول و %s تنوع. لطفاً فیلترها را محدودتر کنید.',
            number_format((int) ($stats['products_count'] ?? 0)),
            number_format((int) ($stats['variants_count'] ?? 0))
        );
    }

    private function redirectFilters(array $filters): array
    {
        return collect($filters)
            ->except('format')
            ->reject(fn ($value) => $value === null || $value === '' || $value === [])
            ->all();
    }

    private function prepareLongRunningExport(): void
    {
        @set_time_limit(0);

        if (function_exists('ini_set')) {
            @ini_set('memory_limit', '1024M');
            @ini_set('pcre.backtrack_limit', '10000000');
            @ini_set('pcre.recursion_limit', '10000000');
        }
    }

    private function createMpdf(): Mpdf
    {
        $mpdf = new Mpdf([
            'mode' => 'utf-8',
            'format' => 'A4-L',
            'directionality' => 'rtl',
            'autoScriptToLang' => true,
            'autoLangToFont' => true,
            'useSubstitutions' => true,
            'default_font' => 'dejavusans',
            'default_font_size' => 9,
            'margin_left' => 8,
            'margin_right' => 8,
            'margin_top' => 10,
            'margin_bottom' => 14,
            'margin_header' => 0,
            'margin_footer' => 6,
            'tempDir' => storage_path('app/mpdf-temp'),
            'simpleTables' => true,
            'packTableData' => true,
            'shrink_tables_to_fit' => 1,
        ]);

        $mpdf->SetDirectionality('rtl');
        $mpdf->SetTitle('گزارش محصولات');
        $mpdf->SetAuthor(config('app.name', 'سامانه انبارداری'));
        $mpdf->SetCreator(config('app.name', 'سامانه انبارداری'));

        $mpdf->debug = false;
        $mpdf->showImageErrors = false;

        return $mpdf;
    }

    private function preparePdfEnvironment(): void
    {
        $directory = storage_path('app/mpdf-temp');

        File::ensureDirectoryExists($directory, 0775, true);
    }

    private function pdfFooter(array $meta): string
    {
        $storeName = e(
            $meta['store_name']
                ?? config('app.name', 'سامانه انبارداری')
        );

        return '
            <div style="
                direction: rtl;
                font-family: vazirmatn;
                font-size: 8pt;
                color: #64748b;
                border-top: 1px solid #cbd5e1;
                padding-top: 4px;
            ">
                <table width="100%" style="border-collapse: collapse;">
                    <tr>
                        <td style="text-align: right; border: 0;">
                            ' . $storeName . '
                        </td>

                        <td style="text-align: left; border: 0;">
                            صفحه {PAGENO} از {nbpg}
                        </td>
                    </tr>
                </table>
            </div>
        ';
    }

    private function pdfStyles(): string
    {
        return <<<'CSS'
        * {
            box-sizing: border-box;
        }

        html,
        body {
            margin: 0;
            padding: 0;
            direction: rtl;
            text-align: right;
            font-family: vazirmatn, sans-serif;
            font-size: 9pt;
            color: #172033;
            line-height: 1.6;
        }

        table,
        tr,
        th,
        td,
        div,
        span {
            font-family: vazirmatn, sans-serif;
        }

        .report-header {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
            background-color: #1e293b;
        }

        .report-header td {
            padding: 12px;
            border: 0;
            color: #ffffff;
            vertical-align: middle;
        }

        .report-title {
            font-size: 17pt;
            font-weight: bold;
            color: #ffffff;
        }

        .report-subtitle {
            margin-top: 3px;
            color: #cbd5e1;
            font-size: 8pt;
        }

        .report-date {
            width: 28%;
            text-align: left;
            color: #ffffff;
        }

        .meta-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 10px;
        }

        .meta-table td {
            width: 25%;
            padding: 7px;
            border: 1px solid #dbe3ec;
            background-color: #f8fafc;
            vertical-align: middle;
        }

        .meta-label {
            color: #64748b;
            font-size: 7.5pt;
        }

        .meta-value {
            margin-top: 2px;
            color: #1e293b;
            font-weight: bold;
        }

        .summary {
            padding: 7px 10px;
            margin-bottom: 10px;
            background-color: #eff6ff;
            border-right: 4px solid #2563eb;
            color: #1e40af;
        }

        .products-table {
            width: 100%;
            border-collapse: collapse;
            table-layout: fixed;
            page-break-inside: auto;
        }

        .products-table thead {
            display: table-header-group;
        }

        .products-table tr {
            page-break-inside: auto;
        }

        .products-table th {
            padding: 7px 4px;
            border: 1px solid #334155;
            background-color: #334155;
            color: #ffffff;
            text-align: center;
            vertical-align: middle;
            font-size: 8pt;
        }

        .products-table td {
            padding: 6px 4px;
            border: 1px solid #dbe3ec;
            text-align: center;
            vertical-align: middle;
            background-color: #ffffff;
        }

        .products-table tbody tr:nth-child(even) td {
            background-color: #f8fafc;
        }

        .product-name {
            text-align: right;
            font-weight: bold;
            color: #0f172a;
        }

        .product-unit,
        .muted {
            color: #64748b;
            font-size: 7pt;
        }

        .product-unit {
            margin-top: 2px;
            text-align: right;
        }

        .number,
        .code {
            direction: ltr;
            unicode-bidi: embed;
            text-align: center;
            white-space: nowrap;
        }

        .product-image {
            width: 40px;
            height: 40px;
            border: 1px solid #dbe3ec;
            padding: 2px;
        }

        .price {
            font-weight: bold;
            white-space: nowrap;
        }

        .price-label {
            display: block;
            color: #64748b;
            font-size: 7pt;
        }

        .badge {
            display: inline-block;
            font-size: 7pt;
            font-weight: bold;
            padding: 2px 5px;
            white-space: nowrap;
        }

        .badge-success {
            color: #067647;
            background-color: #dcfae6;
        }

        .badge-warning {
            color: #9a6700;
            background-color: #fef0c7;
        }

        .badge-danger {
            color: #b42318;
            background-color: #fee4e2;
        }

        .badge-secondary {
            color: #475569;
            background-color: #e2e8f0;
        }

        .variant-row td {
            background-color: #f1f5f9;
            font-size: 7.5pt;
        }

        .variant-name {
            text-align: right;
            padding-right: 10px;
        }

        .variant-label {
            color: #2563eb;
            font-weight: bold;
        }
        CSS;
    }

    private function modelListsByBrand(array $filters)
    {
        $categoryId = ! empty($filters['category_id'])
            ? (int) $filters['category_id']
            : null;

        return ModelList::query()
            ->when($categoryId !== null, function ($query) use ($categoryId) {
                $query->whereHas('productVariants.product', function ($productQuery) use ($categoryId) {
                    $productQuery->where('category_id', $categoryId);
                });
            })
            ->orderBy('brand')
            ->orderBy('model_name')
            ->get(['id', 'brand', 'model_name', 'code'])
            ->groupBy(fn (ModelList $modelList) => $modelList->brand ?: 'سایر');
    }

    private function validatedFilters(
        Request $request,
        bool $requireFormat = false
    ): array {
        $validated = $request->validate([
            'category_id' => [
                'nullable',
                'integer',
                'exists:categories,id',
            ],
            'warehouse_id' => [
                'nullable',
                'integer',
                'exists:warehouses,id',
            ],
            'stock_status' => [
                'nullable',
                'in:all,in_stock,out_of_stock,low_stock',
            ],
            'search' => [
                'nullable',
                'string',
                'max:255',
            ],
            'model_list_ids' => [
                'nullable',
                'array',
            ],
            'model_list_ids.*' => [
                'integer',
                'exists:model_lists,id',
            ],
            'format' => [
                $requireFormat ? 'required' : 'nullable',
                'in:pdf',
            ],
        ]);

        return [
            'category_id' => $validated['category_id'] ?? null,
            'warehouse_id' => $validated['warehouse_id'] ?? null,
            'stock_status' => $validated['stock_status'] ?? 'all',
            'search' => trim((string) ($validated['search'] ?? '')),
            'model_list_ids' => collect($validated['model_list_ids'] ?? [])
                ->map(fn ($id) => (int) $id)
                ->filter()
                ->unique()
                ->values()
                ->all(),
            'format' => 'pdf',
        ];
    }
}
