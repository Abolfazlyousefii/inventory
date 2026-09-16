<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Support\IranLocations;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class CustomerApiController extends Controller
{
    public function search(Request $request)
    {
        $q = trim((string)$request->query('q',''));
        if ($q === '') {
            return response()->json(['data' => ['customers' => []]]);
        }

        $terms = collect([
            $q,
            $this->normalizeDigits($q),
            $this->toPersianDigits($q),
            $this->toArabicDigits($q),
        ])->map(fn ($term) => trim((string) $term))
            ->filter()
            ->unique()
            ->values();
        $fullNameExpression = DB::connection()->getDriverName() === 'sqlite'
            ? "TRIM(COALESCE(first_name, '') || ' ' || COALESCE(last_name, ''))"
            : "TRIM(CONCAT(COALESCE(first_name, ''), ' ', COALESCE(last_name, '')))";

        $items = Customer::query()
            ->withBalance()
            ->where(function($qq) use ($terms, $fullNameExpression){
                foreach ($terms as $term) {
                    $like = '%' . str_replace(['%', '_'], ['\%', '\_'], $term) . '%';
                    $compactLike = '%' . str_replace(['%', '_'], ['\%', '\_'], preg_replace('/[\s\-()]+/u', '', $term)) . '%';

                    $qq->orWhere('first_name', 'like', $like)
                       ->orWhere('last_name', 'like', $like)
                       ->orWhereRaw("{$fullNameExpression} LIKE ?", [$like])
                       ->orWhere('mobile', 'like', $like);

                    if ($compactLike !== '%%') {
                        $qq->orWhereRaw("REPLACE(REPLACE(REPLACE(REPLACE(COALESCE(mobile, ''), ' ', ''), '-', ''), '(', ''), ')', '') LIKE ?", [$compactLike]);
                    }
                }
            })
            ->orderByDesc('id')
            ->limit(20)
            ->get()
            ->map(fn($c)=>[
                'id' => $c->id,
                'name' => $c->display_name,
                'first_name' => $c->first_name,
                'last_name' => $c->last_name,
                'mobile' => $c->mobile,
                'address' => $c->address,
                'postal_code' => $c->postal_code,
                'extra_description' => $c->extra_description,
                'province_id' => (int)($c->province_id ?? 0),
                'city_id' => (int)($c->city_id ?? 0),
                'debt' => (int)$c->debt,
                'credit' => (int)$c->credit,
                'balance' => (int)$c->balance,
                'reservation_tier' => $c->reservation_tier,
                'reservation_tier_label' => $this->reservationTierLabel($c->reservation_tier),
                'reservation_duration_label' => $this->reservationDurationLabel($c->reservation_tier),
            ])
            ->values();

        return response()->json(['data' => ['customers' => $items]]);
    }

    private function normalizeDigits(string $value): string
    {
        return strtr($value, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
    }

    private function toPersianDigits(string $value): string
    {
        return strtr($this->normalizeDigits($value), [
            '0' => '۰', '1' => '۱', '2' => '۲', '3' => '۳', '4' => '۴',
            '5' => '۵', '6' => '۶', '7' => '۷', '8' => '۸', '9' => '۹',
        ]);
    }

    private function toArabicDigits(string $value): string
    {
        return strtr($this->normalizeDigits($value), [
            '0' => '٠', '1' => '١', '2' => '٢', '3' => '٣', '4' => '٤',
            '5' => '٥', '6' => '٦', '7' => '٧', '8' => '٨', '9' => '٩',
        ]);
    }


    private function reservationTierLabel(?string $tier): string
    {
        return match ($tier) {
            'vip' => 'VIP',
            'normal' => 'معمولی',
            'new_or_low_purchase' => 'جدید / کم‌خرید',
            default => 'معمولی پیش‌فرض',
        };
    }

    private function reservationDurationLabel(?string $tier): string
    {
        return match ($tier) {
            'vip' => 'رزرو بدون محدودیت زمانی',
            'new_or_low_purchase' => 'رزرو ۱ ساعته',
            default => 'رزرو ۳ ساعته',
        };
    }

    public function show(Customer $customer)
    {
        $customer->loadSum(['ledgers as debit_sum' => fn($q)=>$q->where('type','debit')],'amount')
                 ->loadSum(['ledgers as credit_sum' => fn($q)=>$q->where('type','credit')],'amount');

        return response()->json([
            'data' => [
                'customer' => [
                    'id' => $customer->id,
                    'name' => $customer->display_name,
                    'first_name' => $customer->first_name,
                    'last_name' => $customer->last_name,
                    'mobile' => $customer->mobile,
                    'address' => $customer->address,
                    'postal_code' => $customer->postal_code,
                    'extra_description' => $customer->extra_description,
                    'province_id' => (int)($customer->province_id ?? 0),
                    'city_id' => (int)($customer->city_id ?? 0),
                    'debt' => (int)$customer->debt,
                    'credit' => (int)$customer->credit,
                    'balance' => (int)$customer->balance,
                    'reservation_tier' => $customer->reservation_tier,
                    'reservation_tier_label' => $this->reservationTierLabel($customer->reservation_tier),
                    'reservation_duration_label' => $this->reservationDurationLabel($customer->reservation_tier),
                ]
            ]
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'customer_name' => 'nullable|string|max:255',
            'first_name' => 'nullable|string|max:255',
            'last_name'  => 'nullable|string|max:255',
            'mobile'     => ['required','string','max:20', Rule::unique('customers','mobile')],
            'address'    => 'nullable|string|max:2000',
            'postal_code' => 'nullable|string|max:20',
            'extra_description' => 'nullable|string|max:2000',
            'province_id'=> 'nullable|integer',
            'city_id'    => 'nullable|integer',
        ]);

        $provinceId = !empty($data['province_id']) ? (int) $data['province_id'] : null;
        $cityId = !empty($data['city_id']) ? (int) $data['city_id'] : null;

        if ($provinceId && !IranLocations::provinceExists($provinceId)) {
            throw ValidationException::withMessages(['province_id' => 'استان انتخاب‌شده معتبر نیست.']);
        }

        if (!IranLocations::cityBelongsToProvince($provinceId, $cityId)) {
            throw ValidationException::withMessages(['city_id' => 'شهر انتخاب‌شده با استان انتخاب‌شده همخوانی ندارد.']);
        }

        $customer = Customer::create([
            'first_name' => $data['customer_name'] ?? $data['first_name'] ?? '',
            'last_name' => $data['last_name'] ?? null,
            'mobile' => $data['mobile'],
            'address' => $data['address'] ?? null,
            'postal_code' => $data['postal_code'] ?? null,
            'extra_description' => $data['extra_description'] ?? null,
            'province_id' => $provinceId,
            'city_id' => $cityId,
        ]);

        return response()->json([
            'data' => [
                'customer' => [
                    'id' => $customer->id,
                    'name' => $customer->display_name,
                    'first_name' => $customer->first_name,
                    'last_name' => $customer->last_name,
                    'mobile' => $customer->mobile,
                    'address' => $customer->address,
                    'postal_code' => $customer->postal_code,
                    'extra_description' => $customer->extra_description,
                    'province_id' => (int)($customer->province_id ?? 0),
                    'city_id' => (int)($customer->city_id ?? 0),
                ]
            ]
        ], 201);
    }
}
