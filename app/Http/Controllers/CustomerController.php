<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Actions\Customers\CreateCustomerAction;
use App\Actions\Customers\UpdateCustomerAction;
use App\Http\Requests\Customer\StoreCustomerRequest;
use App\Http\Requests\Customer\UpdateCustomerRequest;
use App\Support\IranLocations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use Throwable;

class CustomerController extends Controller
{
    public function index(Request $request)
    {
        $q = trim($this->toEnglishDigits((string) $request->get('q')));
        $reservationTier = (string) $request->get('reservation_tier', '');
        $balanceStatus = (string) $request->get('balance_status', '');
        $status = (string) $request->get('status', '');

        $customersQuery = Customer::query()
            ->withBalance()
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($sub) use ($q) {
                    $sub->where('first_name', 'like', "%{$q}%")
                        ->orWhere('name', 'like', "%{$q}%")
                        ->orWhere('company_name', 'like', "%{$q}%")
                        ->orWhere('city', 'like', "%{$q}%")
                        ->orWhereHas('phones', fn ($phones) => $phones->where('phone', 'like', "%{$q}%"))
                        ->orWhere('last_name', 'like', "%{$q}%")
                        ->orWhere('mobile', 'like', "%{$q}%")
                        ->orWhere('address', 'like', "%{$q}%")
                        ->orWhere('extra_description', 'like', "%{$q}%");

                    if (ctype_digit($q)) {
                        $sub->orWhere('id', (int) $q);
                    }
                });
            })
            ->when(in_array($reservationTier, ['vip', 'normal', 'new_or_low_purchase'], true), function ($query) use ($reservationTier) {
                $query->where('reservation_tier', $reservationTier);
            })
            ->when($reservationTier === 'unset', function ($query) {
                $query->whereNull('reservation_tier');
            })
            ->when($status === 'active', fn ($query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn ($query) => $query->where('is_active', false));

        if (in_array($balanceStatus, ['debt', 'credit', 'settled'], true)) {
            $balanceExpression = '(COALESCE(opening_balance, 0) + COALESCE(debit_sum, 0) - COALESCE(credit_sum, 0))';

            match ($balanceStatus) {
                'debt' => $customersQuery->havingRaw("{$balanceExpression} > 0"),
                'credit' => $customersQuery->havingRaw("{$balanceExpression} < 0"),
                'settled' => $customersQuery->havingRaw("{$balanceExpression} = 0"),
            };
        }

        $customers = $customersQuery
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('customers.index', compact('customers', 'q', 'reservationTier', 'balanceStatus'));
    }

    public function create()
    {
        return view('customers.create');
    }

    public function show(Customer $customer)
    {
        return view('customers.show', compact('customer'));
    }

    public function edit(Customer $customer)
    {
        return view('customers.edit', compact('customer'));
    }

    public function store(StoreCustomerRequest $request, CreateCustomerAction $action)
    {
        $customer = $action->execute($request->validated());

        return redirect()
            ->route('customers.show', $customer)
            ->with('success', '✅ مشتری با موفقیت ساخته شد.');
    }

    public function update(UpdateCustomerRequest $request, Customer $customer, UpdateCustomerAction $action)
    {
        $action->execute($customer, $request->validated());

        return redirect()
            ->route('customers.show', $customer)
            ->with('success', '✅ اطلاعات مشتری ویرایش شد.');
    }

    public function destroy(Customer $customer)
    {
        $title = '';
        $customer->delete();

        return redirect()
            ->route('customers.index')
            ->with('success', "✅ مشتری {$title} حذف شد.");
    }
private function parseAmount($value): ?int
{
    if ($value === null) {
        return null;
    }

    $value = $this->toEnglishDigits((string) $value);
    $value = trim($value);

    if ($value === '') {
        return null;
    }

    $value = str_replace([',', '٬', ' '], '', $value);
    $value = preg_replace('/[^\d\-]/', '', $value);

    if ($value === '' || $value === '-') {
        return null;
    }

    return (int) $value;
}
    public function import(Request $request)
    {
        $request->validate([
            'file' => ['required', 'file', 'mimes:xlsx,xls'],
        ]);

        try {
            $spreadsheet = IOFactory::load($request->file('file')->getRealPath());
            $sheet = $spreadsheet->getActiveSheet();
            $rows = $sheet->toArray(null, true, true, false);
        } catch (Throwable $e) {
            return redirect()
                ->route('customers.index')
                ->with('error', 'فایل اکسل خوانده نشد.');
        }

        if (count($rows) < 2) {
            return redirect()
                ->route('customers.index')
                ->with('error', 'فایل اکسل خالی است.');
        }

        $created = 0;
        $updated = 0;
        $skippedNoMobile = 0;
        $skippedDuplicateInFile = 0;

        $seenMobiles = [];

        DB::beginTransaction();

       try {
    foreach (array_slice($rows, 1) as $row) {
        /*
         فایل شما:
         A = کد
         B = نام
         C = نام خانوادگی
         D = ماهیت
         J = آدرس
         K = تلفن‌ها
         L = نام معرف
         M = کد ملی

         اگر فایل شما این ستون‌ها را هم دارد:
         N = بدهکار
         O = بستانکار
         P = مانده
        */

        $oldCode      = $this->cleanCell($row[0] ?? null);
        $firstName    = $this->cleanCell($row[1] ?? null);
        $lastNameRaw  = $this->cleanCell($row[2] ?? null);
        $nature       = $this->cleanCell($row[3] ?? null);
        $address      = $this->cleanCell($row[9] ?? null);
        $phonesRaw    = $this->cleanCell($row[10] ?? null);
        $referrer     = $this->cleanCell($row[11] ?? null);
        $nationalCode = $this->cleanCell($row[12] ?? null);

        // ستون‌های مالی
        $debitRaw   = $this->cleanCell($row[5] ?? null); // N
        $creditRaw  = $this->cleanCell($row[6] ?? null); // O
        $balanceRaw = $this->cleanCell($row[4] ?? null); // P

        $debitAmount   = $this->parseAmount($debitRaw);
        $creditAmount  = $this->parseAmount($creditRaw);
        $balanceAmount = $this->parseAmount($balanceRaw);


        // اولویت با ستون مانده است
        if ($balanceAmount !== null) {
            $openingBalance = $balanceAmount;
        } elseif ($debitAmount !== null || $creditAmount !== null) {
            $openingBalance = (int) ($debitAmount ?? 0) - (int) ($creditAmount ?? 0);
        } else {
            $openingBalance = null;
        }

        $mobiles = $this->extractMobiles($phonesRaw, $lastNameRaw);
        $mobile = $mobiles[0] ?? null;

        if (!$mobile) {
            $skippedNoMobile++;
            continue;
        }

        if (isset($seenMobiles[$mobile])) {
            $skippedDuplicateInFile++;
            continue;
        }

        $seenMobiles[$mobile] = true;

        $lastName = $this->looksLikeMobile($lastNameRaw) ? null : $lastNameRaw;

        $importDescription = $this->buildImportDescription(
            oldCode: $oldCode,
            nature: $nature,
            referrer: $referrer,
            nationalCode: $nationalCode,
            mobiles: $mobiles
        );

        $customer = Customer::where('mobile', $mobile)->first();

        if ($customer) {
            $customer->update([
                'first_name' => $firstName ?: $customer->first_name,
                'last_name' => $lastName ?: $customer->last_name,
                'mobile' => $mobile,
                'address' => $address ?: $customer->address,
                'postal_code' => $customer->postal_code,
                'extra_description' => $this->mergeDescriptions(
                    $customer->extra_description,
                    $importDescription
                ),
                'province_id' => $customer->province_id,
                'city_id' => $customer->city_id,
                'opening_balance' => $balanceAmount ,
            ]);

            $updated++;
        } else {
            Customer::create([
                'first_name' => $firstName ?: 'بدون نام',
                'last_name' => $lastName,
                'mobile' => $mobile,
                'address' => $address,
                'postal_code' => null,
                'extra_description' => $importDescription,
                'province_id' => null,
                'city_id' => null,
                'opening_balance' => $openingBalance ?? 0,
            ]);

            $created++;
        }
    }

    DB::commit();

    return redirect()
        ->route('customers.index')
        ->with(
            'success',
            "✅ ایمپورت انجام شد. {$created} مشتری جدید ساخته شد، {$updated} مشتری آپدیت شد، {$skippedNoMobile} ردیف بدون موبایل رد شد، {$skippedDuplicateInFile} ردیف تکراری داخل فایل رد شد."
        );
} catch (Throwable $e) {
    DB::rollBack();

    return redirect()
        ->route('customers.index')
        ->with('error', 'هنگام ایمپورت خطا رخ داد: ' . $e->getMessage());
}
    }

    private function validatedCustomerPayload(Request $request, ?Customer $customer = null): array
    {
        $data = $request->validate([
            'customer_name' => ['required', 'string', 'max:255'],
            'mobile' => ['required', 'string', 'max:20'],
            'address' => ['nullable', 'string', 'max:1000'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'extra_description' => ['nullable', 'string', 'max:2000'],
            'province_id' => ['nullable', 'integer'],
            'city_id' => ['nullable', 'integer'],
            'reservation_tier' => ['nullable', 'in:vip,normal,new_or_low_purchase'],
        ]);

        $mobile = $this->normalizeMobile($data['mobile'] ?? null);

        if (!$mobile) {
            throw ValidationException::withMessages([
                'mobile' => 'شماره موبایل معتبر نیست.',
            ]);
        }

        $provinceId = !empty($data['province_id']) ? (int) $data['province_id'] : null;
        $cityId = !empty($data['city_id']) ? (int) $data['city_id'] : null;

        if ($provinceId && !IranLocations::provinceExists($provinceId)) {
            throw ValidationException::withMessages([
                'province_id' => 'استان انتخاب‌شده معتبر نیست.',
            ]);
        }

        if (!IranLocations::cityBelongsToProvince($provinceId, $cityId)) {
            throw ValidationException::withMessages([
                'city_id' => 'شهر انتخاب‌شده با استان انتخاب‌شده همخوانی ندارد.',
            ]);
        }

        $duplicateQuery = Customer::query()->where('mobile', $mobile);

        if ($customer) {
            $duplicateQuery->where('id', '!=', $customer->id);
        }

        if ($duplicateQuery->exists()) {
            throw ValidationException::withMessages([
                'mobile' => 'این شماره موبایل قبلاً ثبت شده است.',
            ]);
        }

        $payload = [
            'first_name' => $this->cleanCell($data['customer_name'] ?? null),
            'last_name' => null,
            'mobile' => $mobile,
            'address' => $this->cleanCell($data['address'] ?? null),
            'postal_code' => $this->cleanCell($data['postal_code'] ?? null),
            'extra_description' => $this->cleanCell($data['extra_description'] ?? null),
            'province_id' => $provinceId,
            'city_id' => $cityId,
            'reservation_tier' => ($data['reservation_tier'] ?? null) ?: null,
        ];

        if (!$customer) {
            $payload['opening_balance'] = 0;
        }

        return $payload;
    }

    private function cleanCell($value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim((string) $value);
        $value = preg_replace('/\s+/u', ' ', $value);

        return $value !== '' ? $value : null;
    }

    private function toEnglishDigits(?string $value): string
    {
        $value = (string) $value;

        return strtr($value, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }

    private function normalizeMobile(?string $value): ?string
    {
        $value = $this->toEnglishDigits((string) $value);
        $value = preg_replace('/\D+/', '', $value);

        if (!$value) {
            return null;
        }

        if (str_starts_with($value, '0098')) {
            $value = '0' . substr($value, 4);
        } elseif (str_starts_with($value, '98')) {
            $value = '0' . substr($value, 2);
        } elseif (strlen($value) === 10 && str_starts_with($value, '9')) {
            $value = '0' . $value;
        }

        return preg_match('/^09\d{9}$/', $value) ? $value : null;
    }

    private function extractMobiles(...$values): array
    {
        $found = [];

        foreach ($values as $value) {
            $text = $this->toEnglishDigits((string) $value);

            preg_match_all('/(?:\+98|0098|98|0)?9\d{9}/', $text, $matches);

            foreach ($matches[0] as $rawMobile) {
                $mobile = $this->normalizeMobile($rawMobile);

                if ($mobile) {
                    $found[$mobile] = $mobile;
                }
            }
        }

        return array_values($found);
    }

    private function looksLikeMobile(?string $value): bool
    {
        return !empty($this->extractMobiles($value));
    }

    private function buildImportDescription(
        ?string $oldCode,
        ?string $nature,
        ?string $referrer,
        ?string $nationalCode,
        array $mobiles
    ): ?string {
        $lines = array_filter([
            $oldCode ? "کد فایل قبلی: {$oldCode}" : null,
            $nature ? "ماهیت: {$nature}" : null,
            $referrer ? "نام معرف: {$referrer}" : null,
            $nationalCode ? "کد ملی: {$nationalCode}" : null,
            count($mobiles) > 1 ? 'تلفن‌های فایل: ' . implode(' ، ', $mobiles) : null,
        ]);

        return $this->limitText(implode(PHP_EOL, $lines), 1900);
    }

    private function mergeDescriptions(?string $old, ?string $new): ?string
    {
        $old = trim((string) $old);
        $new = trim((string) $new);

        if ($old === '') {
            return $new !== '' ? $this->limitText($new, 1900) : null;
        }

        if ($new === '') {
            return $this->limitText($old, 1900);
        }

        if (str_contains($old, $new)) {
            return $this->limitText($old, 1900);
        }

        return $this->limitText($old . PHP_EOL . '---' . PHP_EOL . $new, 1900);
    }

    private function limitText(?string $text, int $limit = 1900): ?string
    {
        $text = trim((string) $text);

        if ($text === '') {
            return null;
        }

        return mb_substr($text, 0, $limit);
    }
}
