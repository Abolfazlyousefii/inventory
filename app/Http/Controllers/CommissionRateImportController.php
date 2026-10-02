<?php

namespace App\Http\Controllers;

use App\Models\CommissionRateRevision;
use App\Models\Product;
use App\Services\Commissions\CommissionRateService;
use App\Support\Percentage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

class CommissionRateImportController extends Controller
{
    private const SESSION_KEY = 'commission_rate_imports';

    private const BATCH_SIZE = 20;

    public function create(): View
    {
        return view('finance.commission-rates.import');
    }

    public function preview(Request $request): View
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240'],
        ]);

        $analysis = $this->analyzeSpreadsheet($request->file('file')->getRealPath());

        if (($analysis['summary']['total_rows'] ?? 0) === 0) {
            throw ValidationException::withMessages([
                'file' => 'هیچ ردیف کالایی در فایل پیدا نشد.',
            ]);
        }

        $token = (string) Str::uuid();
        $manifestPath = "commission-rate-imports/{$token}.json";
        $manifest = [
            'created_at' => now()->toIso8601String(),
            'effective_from' => null,
            'source_name' => $request->file('file')->getClientOriginalName(),
            'summary' => $analysis['summary'],
            'rows' => $analysis['rows'],
        ];

        Storage::disk('local')->put(
            $manifestPath,
            json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
        );

        $request->session()->put(self::SESSION_KEY.'.'.$token, [
            'manifest_path' => $manifestPath,
            'created_at' => now()->toIso8601String(),
        ]);

        return view('finance.commission-rates.import-preview', [
            'token' => $token,
            'manifest' => $manifest,
        ]);
    }

    public function apply(Request $request, CommissionRateService $service): JsonResponse
    {
        $data = $request->validate([
            'token' => ['required', 'uuid'],
            'offset' => ['nullable', 'integer', 'min:0'],
        ]);

        $token = (string) $data['token'];
        $sessionData = $request->session()->get(self::SESSION_KEY.'.'.$token);

        if (! is_array($sessionData) || empty($sessionData['manifest_path'])) {
            return response()->json([
                'ok' => false,
                'message' => 'پیش‌نمایش منقضی شده است. فایل را دوباره بارگذاری کنید.',
            ], 410);
        }

        $manifestPath = (string) $sessionData['manifest_path'];
        if (! Storage::disk('local')->exists($manifestPath)) {
            return response()->json([
                'ok' => false,
                'message' => 'فایل موقت Import پیدا نشد. پیش‌نمایش را دوباره بسازید.',
            ], 410);
        }

        $manifest = json_decode(Storage::disk('local')->get($manifestPath), true, 512, JSON_THROW_ON_ERROR);

        if (empty($manifest['effective_from'])) {
            // One timestamp is frozen for the whole import so all products switch together,
            // even though the browser applies the rows in multiple small batches.
            $manifest['effective_from'] = now()->toDateTimeString();
            Storage::disk('local')->put(
                $manifestPath,
                json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR)
            );
        }

        $readyRows = array_values(array_filter(
            $manifest['rows'] ?? [],
            fn (array $row) => ($row['status'] ?? null) === 'ready'
        ));

        $offset = (int) ($data['offset'] ?? 0);
        $batch = array_slice($readyRows, $offset, self::BATCH_SIZE);
        $effectiveFrom = Carbon::parse($manifest['effective_from']);
        $actor = $request->user();

        $applied = 0;
        $inherited = 0;
        $unchanged = 0;
        $errors = [];

        foreach ($batch as $row) {
            $productId = (int) $row['product_id'];

            try {
                $active = CommissionRateRevision::query()
                    ->where('target_key', 'product:'.$productId)
                    ->where('active_marker', 1)
                    ->first();

                if (($row['action'] ?? null) === 'inherit') {
                    if (! $active) {
                        $unchanged++;

                        continue;
                    }

                    if ($effectiveFrom->lte($active->effective_from)) {
                        throw ValidationException::withMessages([
                            'rate' => 'شروع نرخ فعال این محصول بعد از زمان Import است و حذف خودکار آن امن نیست.',
                        ]);
                    }

                    $service->removeRate('product', $productId, $actor, $effectiveFrom);
                    $inherited++;

                    continue;
                }

                $percentage = Percentage::normalize($row['percentage']);

                if ($active && Percentage::normalize($active->percentage) === $percentage) {
                    $unchanged++;

                    continue;
                }

                $service->setRate('product', $productId, $percentage, $actor, $effectiveFrom);
                $applied++;
            } catch (\Throwable $exception) {
                report($exception);
                $errors[] = [
                    'excel_row' => $row['excel_row'] ?? null,
                    'product_id' => $productId,
                    'name' => $row['excel_name'] ?? null,
                    'message' => $exception instanceof ValidationException
                        ? collect($exception->errors())->flatten()->first()
                        : $exception->getMessage(),
                ];
            }
        }

        $nextOffset = $offset + count($batch);
        $done = $nextOffset >= count($readyRows);

        if ($done) {
            Storage::disk('local')->delete($manifestPath);
            $request->session()->forget(self::SESSION_KEY.'.'.$token);
        }

        return response()->json([
            'ok' => true,
            'processed' => count($batch),
            'applied' => $applied,
            'inherited' => $inherited,
            'unchanged' => $unchanged,
            'errors' => $errors,
            'next_offset' => $nextOffset,
            'total' => count($readyRows),
            'done' => $done,
            'effective_from' => $manifest['effective_from'],
        ]);
    }

    private function analyzeSpreadsheet(string $path): array
    {
        $spreadsheet = IOFactory::load($path);
        $sheet = $spreadsheet->getSheetByName('لیست محصولات') ?? $spreadsheet->getActiveSheet();
        [$headerRow, $columns] = $this->findColumns($sheet);

        $products = Product::query()
            ->with('category:id,name')
            ->get(['id', 'name', 'category_id', 'price']);

        $exactIndex = [];
        $compactIndex = [];

        foreach ($products as $product) {
            $exactIndex[$this->normalize($product->name)][] = $product;
            $compactIndex[$this->compact($product->name)][] = $product;
        }

        $activeProductRates = CommissionRateRevision::query()
            ->where('target_type', 'product')
            ->where('active_marker', 1)
            ->get()
            ->keyBy('target_id');

        $rows = [];
        $seenProductIds = [];
        $summary = [
            'total_rows' => 0,
            'with_rate' => 0,
            'inherit' => 0,
            'ready_set' => 0,
            'ready_inherit' => 0,
            'missing_product' => 0,
            'ambiguous' => 0,
            'duplicate_product' => 0,
            'missing_price' => 0,
            'invalid_percentage' => 0,
        ];

        for ($rowNumber = $headerRow + 1; $rowNumber <= $sheet->getHighestDataRow(); $rowNumber++) {
            $name = trim((string) $this->cellValue($sheet, $columns['name'], $rowNumber));
            if ($name === '') {
                continue;
            }

            $summary['total_rows']++;

            $category = trim((string) $this->cellValue($sheet, $columns['category'], $rowNumber));
            $priceRaw = $this->cellValue($sheet, $columns['price'], $rowNumber);
            $commissionRaw = $this->cellValue($sheet, $columns['commission'], $rowNumber);
            $price = is_numeric($priceRaw) ? (int) round((float) $priceRaw) : null;
            $commissionIsBlank = $commissionRaw === null || trim((string) $commissionRaw) === '';
            $commission = ! $commissionIsBlank && is_numeric($commissionRaw)
                ? (int) round((float) $commissionRaw)
                : null;

            $base = [
                'excel_row' => $rowNumber,
                'category' => $category,
                'excel_name' => $name,
                'price' => $price,
                'commission' => $commission,
                'percentage' => null,
                'product_id' => null,
                'product_name' => null,
                'match_type' => null,
                'current_rate' => null,
                'current_rate_effective_from' => null,
                'action' => $commissionIsBlank ? 'inherit' : 'set_rate',
                'status' => null,
                'message' => null,
            ];

            if ($commissionIsBlank) {
                $summary['inherit']++;
            } else {
                $summary['with_rate']++;
            }

            if (! $commissionIsBlank && (! $price || $price <= 0 || $commission === null)) {
                $summary['missing_price']++;
                $rows[] = array_merge($base, [
                    'status' => 'missing_price',
                    'message' => 'پورسانت وارد شده ولی قیمت معتبر برای محاسبه درصد وجود ندارد.',
                ]);

                continue;
            }

            $percentage = null;
            if (! $commissionIsBlank) {
                $percentage = (int) ceil(($commission * 100) / $price);

                if ($percentage < 0 || $percentage > 100) {
                    $summary['invalid_percentage']++;
                    $rows[] = array_merge($base, [
                        'percentage' => $percentage,
                        'status' => 'invalid_percentage',
                        'message' => 'درصد محاسبه‌شده خارج از بازه ۰ تا ۱۰۰ است.',
                    ]);

                    continue;
                }
            }

            [$product, $matchType, $matchStatus] = $this->matchProduct(
                $name,
                $category,
                $price,
                $exactIndex,
                $compactIndex
            );

            if (! $product) {
                $summary[$matchStatus]++;
                $rows[] = array_merge($base, [
                    'percentage' => $percentage,
                    'status' => $matchStatus,
                    'message' => $matchStatus === 'ambiguous'
                        ? 'بیش از یک محصول مشابه پیدا شد؛ این ردیف خودکار ثبت نمی‌شود.'
                        : 'محصول متناظر در دیتابیس پیدا نشد.',
                ]);

                continue;
            }

            $active = $activeProductRates->get($product->id);
            $row = array_merge($base, [
                'percentage' => $percentage,
                'product_id' => (int) $product->id,
                'product_name' => (string) $product->name,
                'match_type' => $matchType,
                'current_rate' => $active?->percentage,
                'current_rate_effective_from' => $active?->effective_from?->toDateTimeString(),
                'status' => 'ready',
                'message' => $commissionIsBlank
                    ? 'پورسانت اکسل خالی است؛ نرخ اختصاصی محصول بسته می‌شود تا نرخ دسته‌بندی اعمال شود.'
                    : ($matchType === 'compact'
                        ? 'تطبیق با نرمال‌سازی فاصله و علائم انجام شد.'
                        : 'تطبیق قطعی نام کالا.'),
            ]);

            if (isset($seenProductIds[$product->id])) {
                $previousIndex = $seenProductIds[$product->id];

                if (($rows[$previousIndex]['status'] ?? null) === 'ready') {
                    if (($rows[$previousIndex]['action'] ?? null) === 'inherit') {
                        $summary['ready_inherit']--;
                    } else {
                        $summary['ready_set']--;
                    }
                    $summary['duplicate_product']++;

                    $rows[$previousIndex]['status'] = 'duplicate_product';
                    $rows[$previousIndex]['message'] = 'بیش از یک ردیف اکسل به همین محصول متصل شده است؛ هیچ‌کدام خودکار ثبت نمی‌شوند.';
                }

                $summary['duplicate_product']++;
                $row['status'] = 'duplicate_product';
                $row['message'] = 'بیش از یک ردیف اکسل به همین محصول متصل شده است؛ هیچ‌کدام خودکار ثبت نمی‌شوند.';
                $rows[] = $row;

                continue;
            }

            $seenProductIds[$product->id] = count($rows);

            if ($commissionIsBlank) {
                $summary['ready_inherit']++;
            } else {
                $summary['ready_set']++;
            }

            $rows[] = $row;
        }

        return compact('rows', 'summary');
    }

    private function matchProduct(
        string $name,
        string $category,
        ?int $price,
        array $exactIndex,
        array $compactIndex
    ): array {
        $exact = $exactIndex[$this->normalize($name)] ?? [];
        $product = $this->pickCandidate($exact, $category, $price);

        if ($product) {
            return [$product, 'exact', 'ready'];
        }

        if (count($exact) > 1) {
            return [null, null, 'ambiguous'];
        }

        $compact = $compactIndex[$this->compact($name)] ?? [];
        $product = $this->pickCandidate($compact, $category, $price);

        if ($product) {
            return [$product, 'compact', 'ready'];
        }

        if (count($compact) > 1) {
            return [null, null, 'ambiguous'];
        }

        return [null, null, 'missing_product'];
    }

    private function pickCandidate(array $candidates, string $category, ?int $price): ?Product
    {
        if (count($candidates) === 1) {
            return $candidates[0];
        }

        if ($candidates === []) {
            return null;
        }

        $categoryKey = $this->normalize($category);
        if ($categoryKey !== '') {
            $categoryMatches = array_values(array_filter(
                $candidates,
                fn (Product $product) => $this->normalize((string) $product->category?->name) === $categoryKey
            ));

            if (count($categoryMatches) === 1) {
                return $categoryMatches[0];
            }

            if ($categoryMatches !== []) {
                $candidates = $categoryMatches;
            }
        }

        if ($price !== null) {
            $priceMatches = array_values(array_filter(
                $candidates,
                fn (Product $product) => (int) $product->price === $price
            ));

            if (count($priceMatches) === 1) {
                return $priceMatches[0];
            }
        }

        return null;
    }

    private function findColumns(Worksheet $sheet): array
    {
        for ($row = 1; $row <= min(20, $sheet->getHighestDataRow()); $row++) {
            $headers = [];

            for ($column = 1; $column <= 9; $column++) {
                $value = trim((string) $this->cellValue($sheet, $column, $row));
                if ($value !== '') {
                    $headers[$column] = $this->normalize($value);
                }
            }

            $nameColumn = array_search($this->normalize('نام کالا'), $headers, true);
            if ($nameColumn === false) {
                continue;
            }

            $categoryColumn = $this->findHeaderColumn($headers, 'دسته‌بندی');
            $priceColumn = $this->findHeaderColumn($headers, 'قیمت کالا');
            $commissionColumn = $this->findHeaderColumn($headers, 'پورسانت سعیدیان');

            if ($categoryColumn && $priceColumn && $commissionColumn) {
                return [$row, [
                    'category' => $categoryColumn,
                    'name' => (int) $nameColumn,
                    'price' => $priceColumn,
                    'commission' => $commissionColumn,
                ]];
            }
        }

        throw ValidationException::withMessages([
            'file' => 'ستون‌های مورد انتظار اکسل پیدا نشد. فایل باید شامل دسته‌بندی، نام کالا، قیمت کالا و پورسانت سعیدیان باشد.',
        ]);
    }

    private function findHeaderColumn(array $headers, string $needle): ?int
    {
        $needle = $this->normalize($needle);

        foreach ($headers as $column => $header) {
            if ($header === $needle || str_contains($header, $needle)) {
                return (int) $column;
            }
        }

        return null;
    }

    private function cellValue(Worksheet $sheet, int $column, int $row): mixed
    {
        $coordinate = Coordinate::stringFromColumnIndex($column).$row;

        return $sheet->getCell($coordinate)->getCalculatedValue();
    }

    private function normalize(string $value): string
    {
        return mb_strtolower(Product::normalizeProductSearchTerm($value));
    }

    private function compact(string $value): string
    {
        return preg_replace('/[\s\-_\.\/]+/u', '', $this->normalize($value)) ?? '';
    }
}
