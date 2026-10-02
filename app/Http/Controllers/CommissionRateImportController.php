<?php

namespace App\Http\Controllers;

use App\Models\CommissionRateRevision;
use App\Models\Product;
use App\Services\Commissions\CommissionRateService;
use App\Support\JalaliDate;
use App\Support\Percentage;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
        return view('finance.commission-rates.import', [
            'defaultEffectiveFrom' => JalaliDate::date(now()->addDay()),
        ]);
    }

    public function preview(Request $request): View
    {
        $data = $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240'],
            'effective_from' => ['required', 'string', 'max:20'],
        ]);

        $effectiveFrom = $this->parseEffectiveFrom($data['effective_from']);
        $analysis = $this->analyzeSpreadsheet($request->file('file')->getRealPath());

        if (($analysis['summary']['entered'] ?? 0) === 0) {
            throw ValidationException::withMessages([
                'file' => 'هیچ ردیف دارای مبلغ پورسانت در فایل پیدا نشد.',
            ]);
        }

        $token = (string) Str::uuid();
        $manifestPath = "commission-rate-imports/{$token}.json";
        $manifest = [
            'created_at' => now()->toIso8601String(),
            'effective_from' => $effectiveFrom->toDateTimeString(),
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
            'effectiveFromDisplay' => JalaliDate::date($effectiveFrom),
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
        $readyRows = array_values(array_filter(
            $manifest['rows'] ?? [],
            fn (array $row) => ($row['status'] ?? null) === 'ready'
        ));

        $offset = (int) ($data['offset'] ?? 0);
        $batch = array_slice($readyRows, $offset, self::BATCH_SIZE);
        $effectiveFrom = Carbon::parse($manifest['effective_from']);
        $actor = $request->user();

        $applied = 0;
        $unchanged = 0;
        $errors = [];

        foreach ($batch as $row) {
            $productId = (int) $row['product_id'];
            $percentage = Percentage::normalize($row['percentage']);

            try {
                $active = CommissionRateRevision::query()
                    ->where('target_key', 'product:'.$productId)
                    ->where('active_marker', 1)
                    ->first();

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

        if ($done && $errors === []) {
            Storage::disk('local')->delete($manifestPath);
            $request->session()->forget(self::SESSION_KEY.'.'.$token);
        }

        return response()->json([
            'ok' => true,
            'processed' => count($batch),
            'applied' => $applied,
            'unchanged' => $unchanged,
            'errors' => $errors,
            'next_offset' => $nextOffset,
            'total' => count($readyRows),
            'done' => $done,
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
        $summary = [
            'entered' => 0,
            'ready' => 0,
            'missing_product' => 0,
            'ambiguous' => 0,
            'missing_price' => 0,
            'invalid_percentage' => 0,
        ];

        for ($rowNumber = $headerRow + 1; $rowNumber <= $sheet->getHighestDataRow(); $rowNumber++) {
            $name = trim((string) $this->cellValue($sheet, $columns['name'], $rowNumber));
            if ($name === '') {
                continue;
            }

            $commissionRaw = $this->cellValue($sheet, $columns['commission'], $rowNumber);
            if ($commissionRaw === null || $commissionRaw === '') {
                continue;
            }

            $summary['entered']++;

            $category = trim((string) $this->cellValue($sheet, $columns['category'], $rowNumber));
            $priceRaw = $this->cellValue($sheet, $columns['price'], $rowNumber);
            $price = is_numeric($priceRaw) ? (int) round((float) $priceRaw) : null;
            $commission = is_numeric($commissionRaw) ? (int) round((float) $commissionRaw) : null;

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
                'status' => null,
                'message' => null,
            ];

            if (! $price || $price <= 0 || $commission === null) {
                $summary['missing_price']++;
                $rows[] = array_merge($base, [
                    'status' => 'missing_price',
                    'message' => 'قیمت معتبر برای محاسبه درصد وجود ندارد.',
                ]);

                continue;
            }

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
            $summary['ready']++;

            $rows[] = array_merge($base, [
                'percentage' => $percentage,
                'product_id' => (int) $product->id,
                'product_name' => (string) $product->name,
                'match_type' => $matchType,
                'current_rate' => $active?->percentage,
                'current_rate_effective_from' => $active?->effective_from?->toDateTimeString(),
                'status' => 'ready',
                'message' => $matchType === 'compact'
                    ? 'تطبیق با حذف فاصله‌های اضافی انجام شد.'
                    : 'تطبیق قطعی نام کالا.',
            ]);
        }

        return compact('rows', 'summary');
    }

    private function matchProduct(
        string $name,
        string $category,
        int $price,
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

    private function pickCandidate(array $candidates, string $category, int $price): ?Product
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

        $priceMatches = array_values(array_filter(
            $candidates,
            fn (Product $product) => (int) $product->price === $price
        ));

        return count($priceMatches) === 1 ? $priceMatches[0] : null;
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

    private function parseEffectiveFrom(string $value): Carbon
    {
        $value = trim($value);

        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return Carbon::createFromFormat('!Y-m-d', $value, config('app.timezone'))->startOfDay();
        }

        $gregorian = JalaliDate::toGregorianDate($value);
        if ($gregorian === null) {
            throw ValidationException::withMessages([
                'effective_from' => 'تاریخ شروع اعمال نامعتبر است؛ نمونه صحیح: ۱۴۰۵/۰۷/۱۱',
            ]);
        }

        return Carbon::parse($gregorian, config('app.timezone'))->startOfDay();
    }
}
