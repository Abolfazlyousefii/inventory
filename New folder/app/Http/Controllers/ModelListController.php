<?php

namespace App\Http\Controllers;

use App\Models\ModelList;
use App\Models\ProductVariant;
use App\Support\PhoneModelCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ModelListController extends Controller
{
    private function brandGroups(): array
    {
        return [
            'Samsung' => [
                'title' => 'سامسونگ',
                'values' => ['Samsung', 'سامسونگ'],
            ],

            'Xiaomi' => [
                'title' => 'شیائومی',
                'values' => ['Xiaomi', 'Xiaomi/Realme', 'Xiaomi / Realme', 'شیائومی'],
            ],

            'Realme' => [
                'title' => 'ریلمی',
                'values' => ['Realme', 'Xiaomi/Realme', 'Xiaomi / Realme', 'ریلمی'],
            ],

            'Apple' => [
                'title' => 'آیفون',
                'values' => ['Apple (iPhone)', 'Apple', 'iPhone', 'آیفون', 'اپل'],
            ],

            'Huawei' => [
                'title' => 'هواوی',
                'values' => ['Huawei', 'Huawei/Honor', 'Huawei / Honor', 'هواوی'],
            ],

            'Honor' => [
                'title' => 'هانر',
                'values' => ['Honor', 'Huawei/Honor', 'Huawei / Honor', 'هانر'],
            ],

            'Other' => [
                'title' => 'سایر',
                'values' => ['سایر', 'Other', ''],
            ],
        ];
    }

    private function brandSelectOptions(): array
    {
        return [
            'Samsung' => 'سامسونگ',
            'Xiaomi' => 'شیائومی',
            'Realme' => 'ریلمی',
            'Apple (iPhone)' => 'آیفون',
            'Huawei' => 'هواوی',
            'Honor' => 'هانر',
            'سایر' => 'سایر',
        ];
    }

    public function index(Request $request)
    {
        $q = trim((string) $request->get('q', ''));

        $all = ModelList::query()
            ->when($q !== '', function ($query) use ($q) {
                $query->where(function ($sub) use ($q) {
                    $sub->where('model_name', 'like', "%{$q}%")
                        ->orWhere('code', 'like', "%{$q}%")
                        ->orWhere('brand', 'like', "%{$q}%");
                });
            })
            ->orderByRaw('CASE WHEN code IS NULL OR code = "" THEN 1 ELSE 0 END')
            ->orderByRaw('CAST(code AS UNSIGNED) ASC')
            ->orderBy('brand')
            ->orderBy('model_name')
            ->get();

        $groups = [];

        foreach ($this->brandGroups() as $key => $cfg) {
            $groups[$key] = [
                'title' => $cfg['title'],
                'items' => $all->filter(function ($row) use ($cfg) {
                    $brand = trim((string) ($row->brand ?? ''));

                    if ($brand === '') {
                        $brand = 'سایر';
                    }

                    return in_array($brand, $cfg['values'], true);
                })->values(),
            ];
        }

        return view('model-lists.index', [
            'q' => $q,
            'groups' => $groups,
            'brandOptions' => $this->brandSelectOptions(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'brand' => ['required', 'string', 'max:100'],
            'model_name' => ['required', 'string', 'max:255'],
        ]);

        DB::transaction(function () use ($data) {
            $brand = $this->normalizeBrand((string) $data['brand']);
            $modelName = $this->normalizeModelName((string) $data['model_name']);

            $exists = ModelList::query()
                ->whereRaw('LOWER(TRIM(model_name)) = ?', [mb_strtolower($modelName)])
                ->lockForUpdate()
                ->first();

            if ($exists) {
                abort(422, 'این مدل قبلاً ثبت شده است.');
            }

            ModelList::create([
                'brand' => $brand,
                'model_name' => $modelName,
                'code' => $this->nextCode3(),
            ]);
        });

        return redirect()
            ->route('model-lists.index')
            ->with('success', 'مدل با موفقیت ذخیره شد.');
    }

    public function quickStore(Request $request)
    {
        $data = $request->validate([
            'brand' => ['required', 'string', 'max:100'],
            'model_name' => ['required', 'string', 'max:255'],
        ]);

        $brand = $this->normalizeBrand((string) $data['brand']);
        $modelName = $this->normalizeModelName((string) $data['model_name']);

        if ($modelName === '') {
            return response()->json([
                'message' => 'نام مدل نمی‌تواند خالی باشد.',
            ], 422);
        }

        $result = [
            'model' => null,
            'created' => false,
        ];

        DB::transaction(function () use (&$result, $brand, $modelName) {
            $existing = ModelList::query()
                ->whereRaw('LOWER(TRIM(model_name)) = ?', [mb_strtolower($modelName)])
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $fixedBrand = $this->normalizeBrand((string) ($existing->brand ?? ''));

                if ($fixedBrand !== $existing->brand) {
                    $existing->update([
                        'brand' => $fixedBrand,
                    ]);
                }

                $result['model'] = $existing->fresh();
                return;
            }

            $result['model'] = ModelList::create([
                'brand' => $brand,
                'model_name' => $modelName,
                'code' => $this->nextCode3(),
            ]);

            $result['created'] = true;
        });

        $model = $result['model'];
        $created = (bool) $result['created'];

        return response()->json([
            'id' => (int) $model->id,
            'brand' => (string) ($model->brand ?? ''),
            'model_name' => (string) ($model->model_name ?? ''),
            'code' => (string) ($model->code ?? ''),
            'created' => $created,
            'message' => $created
                ? 'مدل لیست جدید با موفقیت ثبت شد.'
                : 'این مدل از قبل وجود دارد و انتخاب شد.',
        ]);
    }

    public function update(Request $request, ModelList $modelList): RedirectResponse
    {
        $data = $request->validate([
            'brand' => ['nullable', 'string', 'max:100'],
            'code' => ['required', 'string', 'max:3'],
            'model_name' => ['required', 'string', 'max:255'],
        ]);

        $code = $this->normalizeCode3Strict($data['code']);

        if ($code === null) {
            return back()->withErrors([
                'code' => 'کد باید دقیقاً ۳ رقمی باشد، مثلاً 016 یا 001.',
            ]);
        }

        $brand = $this->normalizeBrand((string) ($data['brand'] ?? $modelList->brand ?? 'سایر'));
        $modelName = $this->normalizeModelName((string) $data['model_name']);

        $existsByCode = ModelList::query()
            ->where('id', '!=', $modelList->id)
            ->where('code', $code)
            ->exists();

        if ($existsByCode) {
            return back()->withErrors([
                'code' => 'این کد قبلاً برای مدل دیگری ثبت شده است.',
            ]);
        }

        $existsByName = ModelList::query()
            ->where('id', '!=', $modelList->id)
            ->whereRaw('LOWER(TRIM(model_name)) = ?', [mb_strtolower($modelName)])
            ->exists();

        if ($existsByName) {
            return back()->withErrors([
                'model_name' => 'این نام مدل قبلاً ثبت شده است.',
            ]);
        }

        $modelList->update([
            'brand' => $brand,
            'code' => $code,
            'model_name' => $modelName,
        ]);

        return redirect()
            ->route('model-lists.index')
            ->with('success', 'مدل لیست بروزرسانی شد.');
    }

    public function destroy(ModelList $modelList): RedirectResponse
    {
        $modelList->delete();

        return redirect()
            ->route('model-lists.index')
            ->with('success', 'مدل حذف شد.');
    }

    public function assignCodes(): RedirectResponse
    {
        DB::transaction(function () {
            $rows = ModelList::query()
                ->lockForUpdate()
                ->orderBy('id')
                ->get();

            $used = [];

            foreach ($rows as $row) {
                $code = $this->normalizeCode3Strict($row->code);

                if ($code === null || isset($used[$code])) {
                    continue;
                }

                $used[$code] = true;
            }

            $nextInt = max($this->maxUsedInt($used) + 1, 1);

            foreach ($rows as $row) {
                $current = $this->normalizeCode3Strict($row->code);
                $needsFix = $current === null;

                if ($current !== null) {
                    $duplicateCount = ModelList::query()
                        ->where('code', $current)
                        ->count();

                    if ($duplicateCount > 1) {
                        $firstId = ModelList::query()
                            ->where('code', $current)
                            ->min('id');

                        if ((int) $row->id !== (int) $firstId) {
                            $needsFix = true;
                        }
                    }
                }

                $fixedBrand = $this->normalizeBrand((string) ($row->brand ?? ''));

                $updates = [];

                if ($needsFix) {
                    $newCode = $this->nextFreeFrom($used, $nextInt);
                    $updates['code'] = $newCode;

                    $used[$newCode] = true;
                    $nextInt = (int) $newCode + 1;
                }

                if ($fixedBrand !== $row->brand) {
                    $updates['brand'] = $fixedBrand;
                }

                if (!empty($updates)) {
                    $row->update($updates);
                }
            }
        });

        return redirect()
            ->route('model-lists.index')
            ->with('success', 'کدهای مدل‌لیست اصلاح و برندها یکدست شدند.');
    }

    public function importFromProducts(): RedirectResponse
    {
        DB::transaction(function () {
            $names = ProductVariant::query()
                ->select('variant_name')
                ->whereNotNull('variant_name')
                ->where('variant_name', '<>', '')
                ->distinct()
                ->pluck('variant_name');

            foreach ($names as $full) {
                $full = $this->normalizeModelName((string) $full);

                if ($full === '') {
                    continue;
                }

                $base = preg_replace('/\s*طرح\s*\d+$/u', '', $full);
                $base = $this->normalizeModelName((string) $base);

                if ($base === '') {
                    continue;
                }

                $existing = ModelList::query()
                    ->whereRaw('LOWER(TRIM(model_name)) = ?', [mb_strtolower($base)])
                    ->lockForUpdate()
                    ->first();

                $brand = $this->detectBrand($base);

                if ($existing) {
                    $fixedBrand = $this->normalizeBrand((string) ($existing->brand ?? ''));

                    if ($fixedBrand === 'سایر' && $brand !== 'سایر') {
                        $existing->update([
                            'brand' => $brand,
                        ]);
                    } elseif ($fixedBrand !== $existing->brand) {
                        $existing->update([
                            'brand' => $fixedBrand,
                        ]);
                    }

                    continue;
                }

                ModelList::create([
                    'brand' => $brand,
                    'model_name' => $base,
                    'code' => $this->nextCode3(),
                ]);
            }
        });

        return redirect()
            ->route('model-lists.index')
            ->with('success', 'مدل‌ها از کالاهای موجود دریافت و با کد ۳ رقمی ذخیره شدند.');
    }

    public function importPhoneCatalog(): RedirectResponse
    {
        DB::transaction(function () {
            $catalog = PhoneModelCatalog::brands();

            foreach ($catalog as $brand => $models) {
                $brand = $this->normalizeBrand((string) $brand);

                foreach ($models as $modelName) {
                    $normalizedName = $this->normalizeModelName((string) $modelName);

                    if ($normalizedName === '') {
                        continue;
                    }

                    $existing = ModelList::query()
                        ->whereRaw('LOWER(TRIM(model_name)) = ?', [mb_strtolower($normalizedName)])
                        ->lockForUpdate()
                        ->first();

                    if ($existing) {
                        $oldBrand = $this->normalizeBrand((string) ($existing->brand ?? ''));

                        if (
                            $oldBrand === 'سایر' ||
                            $oldBrand === '' ||
                            $oldBrand !== $brand
                        ) {
                            $existing->update([
                                'brand' => $brand,
                            ]);
                        }

                        continue;
                    }

                    ModelList::create([
                        'brand' => $brand,
                        'model_name' => $normalizedName,
                        'code' => $this->nextCode3(),
                    ]);
                }
            }
        });

        return back()->with('success', 'بانک مدل‌های موبایل با موفقیت بارگذاری شد.');
    }

    private function normalizeBrand(string $brand): string
    {
        $brand = trim($brand);

        return match ($brand) {
            'Samsung', 'سامسونگ' => 'Samsung',

            'Apple',
            'iPhone',
            'Apple (iPhone)',
            'آیفون',
            'اپل' => 'Apple (iPhone)',

            'Xiaomi',
            'شیائومی' => 'Xiaomi',

            'Realme',
            'ریلمی' => 'Realme',

            'Huawei',
            'هواوی' => 'Huawei',

            'Honor',
            'هانر' => 'Honor',

            'Xiaomi/Realme',
            'Xiaomi / Realme' => 'Xiaomi',

            'Huawei/Honor',
            'Huawei / Honor' => 'Huawei',

            default => $brand !== '' ? $brand : 'سایر',
        };
    }

    private function normalizeModelName(string $modelName): string
    {
        $modelName = trim($modelName);
        $modelName = preg_replace('/\s+/u', ' ', $modelName);

        return trim((string) $modelName);
    }

    private function normalizeCode3Strict(?string $code): ?string
    {
        $code = trim((string) ($code ?? ''));

        if ($code === '') {
            return null;
        }

        if (!preg_match('/^\d{1,3}$/', $code)) {
            return null;
        }

        return str_pad($code, 3, '0', STR_PAD_LEFT);
    }

    private function nextCode3(): string
    {
        $codes = ModelList::query()
            ->whereNotNull('code')
            ->pluck('code')
            ->all();

        $max = 0;
        $used = [];

        foreach ($codes as $code) {
            $normalized = $this->normalizeCode3Strict($code);

            if ($normalized === null) {
                continue;
            }

            $used[$normalized] = true;

            $intValue = (int) $normalized;

            if ($intValue > $max) {
                $max = $intValue;
            }
        }

        return $this->nextFreeFrom($used, max($max + 1, 1));
    }

    private function nextFreeFrom(array $used, int $start): string
    {
        for ($i = $start; $i <= 999; $i++) {
            $code = str_pad((string) $i, 3, '0', STR_PAD_LEFT);

            if (!isset($used[$code])) {
                return $code;
            }
        }

        for ($i = 1; $i <= 999; $i++) {
            $code = str_pad((string) $i, 3, '0', STR_PAD_LEFT);

            if (!isset($used[$code])) {
                return $code;
            }
        }

        abort(422, 'کد ۳ رقمی خالی برای مدل لیست موجود نیست.');
    }

    private function maxUsedInt(array $used): int
    {
        $max = 0;

        foreach ($used as $code => $true) {
            $intValue = (int) $code;

            if ($intValue > $max) {
                $max = $intValue;
            }
        }

        return $max;
    }

    private function detectBrand(string $modelName): string
    {
        $name = mb_strtolower($modelName);

        return match (true) {
            str_contains($name, 'iphone') ||
            str_contains($name, 'آیفون') ||
            str_contains($name, 'اپل') => 'Apple (iPhone)',

            str_contains($name, 'samsung') ||
            str_contains($name, 'سامسونگ') ||
            str_contains($name, 'galaxy') => 'Samsung',

            str_contains($name, 'realme') ||
            str_contains($name, 'ریلمی') ||
            str_contains($name, 'rmx') ||
            str_contains($name, 'narzo') => 'Realme',

            str_contains($name, 'xiaomi') ||
            str_contains($name, 'شیائومی') ||
            str_contains($name, 'redmi') ||
            str_contains($name, 'poco') ||
            str_contains($name, 'mi ') => 'Xiaomi',

            str_contains($name, 'honor') ||
            str_contains($name, 'هانر') => 'Honor',

            str_contains($name, 'huawei') ||
            str_contains($name, 'هواوی') ||
            str_contains($name, 'nova') ||
            str_contains($name, 'pura') => 'Huawei',

            default => 'سایر',
        };
    }
}