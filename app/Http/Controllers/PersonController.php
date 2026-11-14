<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Supplier;
use App\Support\IranLocations;
use App\Support\PhoneNormalizer;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class PersonController extends Controller
{
    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));
        $page = max(1, (int) $request->query('page', 1));
        $perPage = 20;

        $customers = Customer::query()
            ->orderByDesc('id')
            ->get();

        $suppliers = Supplier::query()
            ->where(fn ($query) => $query->whereNull('is_inventory_adjustment_source')->orWhere('is_inventory_adjustment_source', false))
            ->orderByDesc('id')
            ->get();

        $people = $this->mergePeople($customers, $suppliers);
        // Resolve identity against the full directory before filtering; searching
        // must not hide the other role or make a duplicate phone look unique.
        if ($q !== '') {
            $term = mb_strtolower(strtr($q, ['ي' => 'ی', 'ك' => 'ک']));
            $phone = PhoneNormalizer::normalize($q);
            $customerNames = $customers->keyBy('id');
            $supplierNames = $suppliers->keyBy('id');
            $people = $people->filter(function ($person) use ($term, $phone, $customerNames, $supplierNames) {
                $names = [$person['name'], $customerNames->get($person['customer_id'])?->display_name,
                    $supplierNames->get($person['supplier_id'])?->name];
                return collect($names)->contains(fn ($name) => str_contains(mb_strtolower(strtr((string) $name, ['ي' => 'ی', 'ك' => 'ک'])), $term))
                    || ($phone !== '' && str_contains(PhoneNormalizer::normalize((string) $person['mobile']), $phone));
            })->values();
        }
        $role = in_array($request->query('role'), ['customer', 'supplier', 'both'], true) ? $request->query('role') : '';
        $people = $people->filter(fn ($person) => match ($role) {
            'customer' => $person['is_customer'],
            'supplier' => $person['is_supplier'],
            'both' => $person['is_customer'] && $person['is_supplier'],
            default => true,
        })->values();

        $items = $people->forPage($page, $perPage)->values();
        $pagination = new LengthAwarePaginator(
            $items,
            $people->count(),
            $perPage,
            $page,
            [
                'path' => $request->url(),
                'query' => $request->query(),
            ]
        );

        return view('persons.index', [
            'people' => $pagination,
            'q' => $q,
            'role' => $role,
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'mobile' => ['required', 'string', 'max:20'],
            'types' => ['required', 'array', 'min:1'],
            'types.*' => ['required', Rule::in(['customer', 'supplier'])],
            'address' => ['nullable', 'string', 'max:1000'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:2000'],
            'province_id' => ['nullable', 'integer'],
            'city_id' => ['nullable', 'integer'],
        ]);

        $data['mobile'] = PhoneNormalizer::normalize((string) $data['mobile']);
        if ($data['mobile'] === '') {
            throw ValidationException::withMessages(['mobile' => 'شماره تماس معتبر نیست.']);
        }

        $types = collect($data['types'])->unique()->values();
        $provinceId = !empty($data['province_id']) ? (int) $data['province_id'] : null;
        $cityId = !empty($data['city_id']) ? (int) $data['city_id'] : null;

        if ($provinceId && !IranLocations::provinceExists($provinceId)) {
            throw ValidationException::withMessages(['province_id' => 'استان انتخاب‌شده معتبر نیست.']);
        }

        if (!IranLocations::cityBelongsToProvince($provinceId, $cityId)) {
            throw ValidationException::withMessages(['city_id' => 'شهر انتخاب‌شده با استان انتخاب‌شده همخوانی ندارد.']);
        }

        if ($types->contains('customer')) {
            $duplicateCustomer = Customer::query()->get(['id', 'mobile'])->contains(
                fn (Customer $candidate): bool => PhoneNormalizer::normalize((string) $candidate->mobile) === $data['mobile']
            );
            if ($duplicateCustomer) {
                return back()
                    ->withErrors(['mobile' => 'شماره تماس برای یک مشتری دیگر ثبت شده است.'])
                    ->withInput();
            }
        }

        if ($types->contains('supplier') && Supplier::query()->get(['id', 'phone'])->contains(
            fn (Supplier $candidate): bool => PhoneNormalizer::normalize((string) $candidate->phone) === $data['mobile']
        )) {
            return back()->withErrors(['mobile' => 'این شماره تماس قبلاً برای تأمین‌کننده دیگری ثبت شده است.'])->withInput();
        }

        DB::transaction(function () use ($data, $types, $provinceId, $cityId) {
            if ($types->contains('customer')) {
                Customer::create([
                    'first_name' => $data['name'],
                    'last_name' => null,
                    'mobile' => $data['mobile'],
                    'address' => $data['address'] ?? null,
                    'postal_code' => $data['postal_code'] ?? null,
                    'extra_description' => $data['description'] ?? null,
                    'province_id' => $provinceId,
                    'city_id' => $cityId,
                ]);
            }

            if ($types->contains('supplier')) {
                $province = IranLocations::province($provinceId);
                $city = collect(IranLocations::cities($provinceId))->firstWhere('id', $cityId);

                Supplier::create([
                    'name' => $data['name'],
                    'phone' => $data['mobile'],
                    'province' => $province['name'] ?? null,
                    'city' => $city['name'] ?? null,
                    'address' => $data['address'] ?? null,
                    'postal_code' => $data['postal_code'] ?? null,
                    'additional_notes' => $data['description'] ?? null,
                ]);
            }
        });

        return redirect()->route('persons.index')->with('success', '✅ شخص جدید با موفقیت ثبت شد.');
    }

    public function update(Request $request, string $personKey)
    {
        [$customer, $supplier] = $this->findPersonByKey($personKey);
        abort_if(! $customer && ! $supplier, 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'mobile' => ['required', 'string', 'max:20'],
            'types' => ['required', 'array', 'min:1'],
            'types.*' => ['required', Rule::in(['customer', 'supplier'])],
            'address' => ['nullable', 'string', 'max:1000'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'description' => ['nullable', 'string', 'max:2000'],
            'province_id' => ['nullable', 'integer'],
            'city_id' => ['nullable', 'integer'],
        ]);

        $data['mobile'] = PhoneNormalizer::normalize((string) $data['mobile']);
        if ($data['mobile'] === '') {
            throw ValidationException::withMessages(['mobile' => 'شماره تماس معتبر نیست.']);
        }

        $types = collect($data['types'])->unique()->values();
        $provinceId = !empty($data['province_id']) ? (int) $data['province_id'] : null;
        $cityId = !empty($data['city_id']) ? (int) $data['city_id'] : null;

        if ($provinceId && !IranLocations::provinceExists($provinceId)) {
            throw ValidationException::withMessages(['province_id' => 'استان انتخاب‌شده معتبر نیست.']);
        }

        if (!IranLocations::cityBelongsToProvince($provinceId, $cityId)) {
            throw ValidationException::withMessages(['city_id' => 'شهر انتخاب‌شده با استان انتخاب‌شده همخوانی ندارد.']);
        }

        $duplicateCustomer = Customer::query()->get(['id', 'mobile'])->contains(
            fn (Customer $candidate): bool => (int) $candidate->id !== (int) ($customer?->id ?? 0)
                && PhoneNormalizer::normalize((string) $candidate->mobile) === $data['mobile']
        );

        if ($types->contains('customer') && $duplicateCustomer) {
            return back()
                ->withErrors(['mobile' => 'شماره تماس برای یک مشتری دیگر ثبت شده است.'])
                ->withInput();
        }

        $duplicateSupplier = Supplier::query()->get(['id', 'phone'])->contains(
            fn (Supplier $candidate): bool => (int) $candidate->id !== (int) ($supplier?->id ?? 0)
                && PhoneNormalizer::normalize((string) $candidate->phone) === $data['mobile']
        );

        if ($types->contains('supplier') && $duplicateSupplier) {
            return back()->withErrors(['mobile' => 'این شماره تماس قبلاً برای تأمین‌کننده دیگری ثبت شده است.'])->withInput();
        }

        DB::transaction(function () use ($customer, $supplier, $data, $types, $provinceId, $cityId) {
            $province = IranLocations::province($provinceId);
            $city = collect(IranLocations::cities($provinceId))->firstWhere('id', $cityId);

            if ($types->contains('customer')) {
                $customerData = [
                    'first_name' => $data['name'],
                    'last_name' => null,
                    'mobile' => $data['mobile'],
                    'address' => $data['address'] ?? null,
                    'postal_code' => $data['postal_code'] ?? null,
                    'extra_description' => $data['description'] ?? null,
                    'province_id' => $provinceId,
                    'city_id' => $cityId,
                ];

                $customer ? $customer->update($customerData) : Customer::create($customerData);
            } elseif ($customer) {
                $this->deleteCustomerRole($customer);
            }

            if ($types->contains('supplier')) {
                $supplierData = [
                    'name' => $data['name'],
                    'phone' => $data['mobile'],
                    'province' => $province['name'] ?? null,
                    'city' => $city['name'] ?? null,
                    'address' => $data['address'] ?? null,
                    'postal_code' => $data['postal_code'] ?? null,
                    'additional_notes' => $data['description'] ?? null,
                ];

                $supplier ? $supplier->update($supplierData) : Supplier::create($supplierData);
            } elseif ($supplier) {
                $this->deleteSupplierRole($supplier);
            }
        });

        return redirect()->route('persons.index')->with('success', '✅ اطلاعات شخص با موفقیت بروزرسانی شد.');
    }

    private function mergePeople(Collection $customers, Collection $suppliers): Collection
    {
        $people = collect();
        $customerPhoneCounts = $customers->countBy(fn ($c) => PhoneNormalizer::normalize((string) $c->mobile));
        $supplierPhoneCounts = $suppliers->countBy(fn ($s) => PhoneNormalizer::normalize((string) $s->phone));
        $ambiguous = fn ($phone) => ($customerPhoneCounts[$phone] ?? 0) > 1 || ($supplierPhoneCounts[$phone] ?? 0) > 1;

        foreach ($customers as $customer) {
            $phone = PhoneNormalizer::normalize((string) $customer->mobile);
            $key = $phone !== '' && ! $ambiguous($phone) ? $phone : 'customer-'.$customer->id;
            $people[$key] = [
                'key' => $key,
                'customer_id' => $customer->id,
                'supplier_id' => null,
                'name' => $customer->display_name,
                'mobile' => $customer->mobile,
                'address' => $customer->address,
                'postal_code' => $customer->postal_code,
                'description' => $customer->extra_description,
                'province_id' => $customer->province_id,
                'city_id' => $customer->city_id,
                'is_customer' => true,
                'is_supplier' => false,
                'updated_at' => $customer->updated_at,
            ];
        }

        foreach ($suppliers as $supplier) {
            $phone = PhoneNormalizer::normalize((string) $supplier->phone);
            $key = $phone !== '' && ! $ambiguous($phone) ? $phone : 'supplier-'.$supplier->id;
            $existing = $people->get($key);

            if ($existing) {
                $people[$key] = [
                    ...$existing,
                    'supplier_id' => $existing['supplier_id'] ?? $supplier->id,
                    'name' => $existing['name'] ?: $supplier->name,
                    'mobile' => $existing['mobile'] ?: $supplier->phone,
                    'address' => $existing['address'] ?: $supplier->address,
                    'postal_code' => $existing['postal_code'] ?: $supplier->postal_code,
                    'description' => $existing['description'] ?: $supplier->additional_notes,
                    'is_supplier' => true,
                    'updated_at' => $existing['updated_at'] && $existing['updated_at']->gte($supplier->updated_at) ? $existing['updated_at'] : $supplier->updated_at,
                ];
                continue;
            }

            $people[$key] = [
                'key' => $key,
                'customer_id' => null,
                'supplier_id' => $supplier->id,
                'name' => $supplier->name,
                'mobile' => $supplier->phone,
                'address' => $supplier->address,
                'postal_code' => $supplier->postal_code,
                'description' => $supplier->additional_notes,
                ...$this->supplierLocationIds($supplier),
                'is_customer' => false,
                'is_supplier' => true,
                'updated_at' => $supplier->updated_at,
            ];
        }

        return $people
            ->values()
            ->sortByDesc(fn (array $person) => $person['updated_at'])
            ->values();
    }

    private function findPersonByKey(string $personKey): array
    {
        if (Str::startsWith($personKey, 'customer-')) {
            return [Customer::find((int) Str::after($personKey, 'customer-')), null];
        }

        if (Str::startsWith($personKey, 'supplier-')) {
            return [null, Supplier::find((int) Str::after($personKey, 'supplier-'))];
        }

        $normalized = PhoneNormalizer::normalize($personKey);
        if ($normalized === '') {
            return [null, null];
        }

        $customer = Customer::query()->orderByDesc('id')->get(['id', 'mobile'])
            ->first(fn (Customer $candidate): bool => PhoneNormalizer::normalize((string) $candidate->mobile) === $normalized);
        $supplier = Supplier::query()->orderByDesc('id')->get(['id', 'phone'])
            ->first(fn (Supplier $candidate): bool => PhoneNormalizer::normalize((string) $candidate->phone) === $normalized);

        return [$customer, $supplier];
    }

    private function deleteCustomerRole(Customer $customer): void
    {
        throw ValidationException::withMessages(['types' => 'نقش مشتری قابل حذف نیست؛ اطلاعات و سوابق مشتری باید حفظ شود.']);
    }

    private function deleteSupplierRole(Supplier $supplier): void
    {
        if ($supplier->purchases()->exists()) {
            throw ValidationException::withMessages(['types' => 'به دلیل وجود خرید ثبت‌شده، امکان حذف نقش تامین‌کننده وجود ندارد.']);
        }

        $supplier->delete();
    }

    private function supplierLocationIds(Supplier $supplier): array
    {
        $province = collect(IranLocations::provinces())->firstWhere('name', $supplier->province);
        $city = collect($province['cities'] ?? [])->firstWhere('name', $supplier->city);

        return [
            'province_id' => $province['id'] ?? null,
            'city_id' => $city['id'] ?? null,
        ];
    }

}
