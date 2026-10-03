<?php

namespace App\Http\Controllers;

use App\Models\ActivityLog;
use App\Models\Customer;
use App\Models\CustomerLedger;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\IOFactory;

class AccountStatementImportController extends Controller
{
    private const SESSION_KEY = 'account_statement_imports';
    private const BATCH_SIZE = 20;
    private const HEADERS = ['شناسه مشتری', 'نام مشتری', 'موبایل', 'مانده فعلی نرم‌افزار (ریال)', 'مانده هدف سازه حساب (ریال)', 'کد سازه حساب', 'دلیل اصلاح'];

    public function create(): View
    {
        return view('account-statements.import');
    }

    public function preview(Request $request): View
    {
        $request->validate(['file' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240']]);
        $file = $request->file('file');
        $book = IOFactory::load($file->getRealPath());
        $sheet = $book->getActiveSheet();
        $headers = [];
        for ($column = 1; $column <= count(self::HEADERS); $column++) {
            $headers[] = trim((string) $sheet->getCell([$column, 1])->getValue());
        }
        if ($headers !== self::HEADERS) {
            throw ValidationException::withMessages(['file' => 'ستون‌های فایل با قالب ورود مانده مطابقت ندارند. از فایل آمادهٔ همین بخش استفاده کنید.']);
        }
        if ($sheet->getHighestDataRow() > 2001) {
            throw ValidationException::withMessages(['file' => 'در هر بار حداکثر ۲۰۰۰ مشتری قابل بررسی است.']);
        }

        $rows = [];
        $seen = [];
        $seenMobiles = [];
        for ($number = 2; $number <= $sheet->getHighestDataRow(); $number++) {
            $raw = [];
            $formula = false;
            for ($column = 1; $column <= 7; $column++) {
                $cell = $sheet->getCell([$column, $number]);
                $formula = $formula || $cell->isFormula();
                $raw[] = trim((string) $cell->getValue());
            }
            if (implode('', $raw) === '') {
                continue;
            }
            [$id, $name, $mobile, $expected, $target, $externalCode, $reason] = $raw;
            $idNumber = $this->integer($id, false);
            $expectedNumber = $this->integer($expected, true);
            $targetNumber = $this->integer($target, true);
            $status = 'ready';
            $message = '';
            $customer = $idNumber ? Customer::query()->withBalance()->find($idNumber) : null;
            if ($formula || ! $idNumber || $expectedNumber === null || $targetNumber === null || mb_strlen($reason) < 10 || mb_strlen($reason) > 1000) {
                $status = 'invalid';
                $message = 'شناسه، مانده یا دلیل اصلاح نامعتبر است؛ فرمول هم پذیرفته نمی‌شود.';
            } elseif (isset($seen[$idNumber]) || isset($seenMobiles[$this->mobile($mobile)])) {
                $status = 'invalid';
                $message = 'شناسه یا موبایل مشتری در فایل تکراری است.';
            } elseif (! $customer || $this->normalizedName($customer->display_name) !== $this->normalizedName($name) || $this->mobile($customer->mobile) !== $this->mobile($mobile) || $this->mobile($mobile) === '') {
                $status = 'mismatch';
                $message = 'نام یا موبایل با مشتری نرم‌افزار یکسان نیست.';
            } elseif (Customer::query()->where('mobile', $customer->mobile)->count() !== 1) {
                $status = 'mismatch';
                $message = 'این موبایل در نرم‌افزار یکتا نیست.';
            } elseif ($customer->balance === $targetNumber) {
                $status = 'unchanged';
                $message = 'ماندهٔ هدف پیش‌تر ثبت شده است.';
            } elseif ($customer->balance !== $expectedNumber) {
                $status = 'stale';
                $message = 'مانده از زمان تهیه فایل تغییر کرده است.';
            }
            if ($idNumber) {
                $seen[$idNumber] = true;
            }
            if ($this->mobile($mobile) !== '') {
                $seenMobiles[$this->mobile($mobile)] = true;
            }
            $rows[] = [
                'excel_row' => $number, 'customer_id' => $idNumber, 'name' => $name,
                'mobile' => $mobile, 'expected' => $expectedNumber, 'target' => $targetNumber,
                'current' => $customer?->balance, 'external_code' => $externalCode,
                'reason' => $reason, 'status' => $status, 'message' => $message,
            ];
        }
        $book->disconnectWorksheets();
        if ($rows === []) {
            throw ValidationException::withMessages(['file' => 'هیچ ردیف مشتری در فایل یافت نشد.']);
        }
        $token = (string) Str::uuid();
        $path = "account-statement-imports/{$token}.json";
        $manifest = [
            'user_id' => $request->user()->id,
            'created_at' => now()->toIso8601String(),
            'source_name' => $file->getClientOriginalName(),
            'source_sha256' => hash_file('sha256', $file->getRealPath()),
            'rows' => $rows,
        ];
        Storage::disk('local')->put($path, json_encode($manifest, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
        $request->session()->put(self::SESSION_KEY.'.'.$token, $path);

        return view('account-statements.import-preview', compact('manifest', 'token'));
    }

    public function apply(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'uuid'], 'offset' => ['required', 'integer', 'min:0'], 'confirmed' => ['required', 'accepted']]);
        $token = $data['token'];
        $path = $request->session()->get(self::SESSION_KEY.'.'.$token);
        if (! $path || ! Storage::disk('local')->exists($path)) {
            return response()->json(['ok' => false, 'message' => 'پیش‌نمایش منقضی شده است. فایل را دوباره بارگذاری کنید.'], 410);
        }
        $manifest = json_decode(Storage::disk('local')->get($path), true, 512, JSON_THROW_ON_ERROR);
        if (($manifest['user_id'] ?? null) !== $request->user()->id) {
            abort(403);
        }
        $ready = array_values(array_filter($manifest['rows'], fn ($row) => $row['status'] === 'ready'));
        $offset = (int) $data['offset'];
        $batch = array_slice($ready, $offset, self::BATCH_SIZE);
        $applied = 0;
        $unchanged = 0;
        $errors = [];
        foreach ($batch as $row) {
            try {
                $result = DB::transaction(function () use ($row, $manifest, $token, $request) {
                    $customer = Customer::query()->whereKey($row['customer_id'])->lockForUpdate()->firstOrFail();
                    if ($this->normalizedName($customer->display_name) !== $this->normalizedName($row['name']) || $this->mobile($customer->mobile) !== $this->mobile($row['mobile'])) {
                        throw ValidationException::withMessages(['row' => 'نام یا موبایل مشتری تغییر کرده است.']);
                    }
                    if (Customer::query()->where('mobile', $customer->mobile)->count() !== 1) {
                        throw ValidationException::withMessages(['row' => 'موبایل مشتری دیگر یکتا نیست.']);
                    }
                    $before = (int) $customer->opening_balance
                        + (int) CustomerLedger::query()->effectiveForBalance()->where('customer_id', $customer->id)->where('type', 'debit')->sum('amount')
                        - (int) CustomerLedger::query()->effectiveForBalance()->where('customer_id', $customer->id)->where('type', 'credit')->sum('amount');
                    if ($before === $row['target']) {
                        return 'unchanged';
                    }
                    if ($before !== $row['expected']) {
                        throw ValidationException::withMessages(['row' => 'مانده حساب تغییر کرده است؛ این ردیف ثبت نشد.']);
                    }
                    $difference = $row['target'] - $before;
                    $log = ActivityLog::query()->create([
                        'user_id' => $request->user()->id,
                        'action' => 'customer_balance_adjusted',
                        'subject_type' => Customer::class,
                        'subject_id' => $customer->id,
                        'description' => 'مانده حساب مشتری با سند اصلاحی از ورود اکسل تنظیم شد.',
                        'properties' => [
                            'balance_before' => $before, 'balance_after' => $row['target'],
                            'difference' => $difference, 'reason' => $row['reason'],
                            'source' => 'account_statement_excel_import', 'external_code' => $row['external_code'],
                            'import_token' => $token, 'excel_row' => $row['excel_row'],
                            'source_sha256' => $manifest['source_sha256'],
                        ],
                        'occurred_at' => now(),
                    ]);
                    $ledger = CustomerLedger::query()->create([
                        'customer_id' => $customer->id,
                        'type' => $difference > 0 ? 'debit' : 'credit',
                        'amount' => abs($difference),
                        'reference_type' => ActivityLog::class,
                        'reference_id' => $log->id,
                        'note' => 'سند اصلاح مانده حساب از اکسل #' . $log->id,
                    ]);
                    $log->update(['properties' => array_merge($log->properties, ['ledger_id' => $ledger->id])]);
                    return 'applied';
                }, 3);
                $result === 'applied' ? $applied++ : $unchanged++;
            } catch (\Throwable $exception) {
                report($exception);
                $errors[] = [
                    'excel_row' => $row['excel_row'], 'name' => $row['name'],
                    'message' => $exception instanceof ValidationException
                        ? collect($exception->errors())->flatten()->first() : 'خطا در ثبت سند؛ این ردیف ثبت نشد.',
                ];
            }
        }
        $next = $offset + count($batch);
        $done = $next >= count($ready);
        if ($done) {
            Storage::disk('local')->delete($path);
            $request->session()->forget(self::SESSION_KEY.'.'.$token);
        }
        return response()->json(['ok' => true, 'processed' => count($batch), 'applied' => $applied, 'unchanged' => $unchanged, 'errors' => $errors, 'next_offset' => $next, 'total' => count($ready), 'done' => $done]);
    }

    private function integer(string $value, bool $signed): ?int
    {
        $value = strtr($value, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
        $value = preg_replace('/[\s,٬،]+/u', '', $value);
        if (! preg_match($signed ? '/^-?\d{1,16}$/' : '/^[1-9]\d{0,15}$/', $value)) {
            return null;
        }
        $number = (int) $value;
        return abs($number) <= 1_000_000_000_000_000 ? $number : null;
    }

    private function mobile(?string $value): string
    {
        $value = strtr((string) $value, ['۰'=>'0','۱'=>'1','۲'=>'2','۳'=>'3','۴'=>'4','۵'=>'5','۶'=>'6','۷'=>'7','۸'=>'8','۹'=>'9','٠'=>'0','١'=>'1','٢'=>'2','٣'=>'3','٤'=>'4','٥'=>'5','٦'=>'6','٧'=>'7','٨'=>'8','٩'=>'9']);
        return preg_replace('/\D+/', '', $value);
    }

    private function normalizedName(string $value): string
    {
        $value = strtr(mb_strtolower($value), ['ي'=>'ی','ك'=>'ک','أ'=>'ا','إ'=>'ا']);
        return preg_replace('/\s+/u', ' ', trim($value));
    }
}
