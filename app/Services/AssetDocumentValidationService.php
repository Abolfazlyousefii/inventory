<?php

namespace App\Services;

use App\Models\AssetDocumentItemCode;
use Illuminate\Validation\ValidationException;

class AssetDocumentValidationService
{
    public function __construct(private readonly AssetCodeService $assetCodeService) {}

    public function normalizeAndValidateItems(array $items, ?int $excludeDocumentId = null): array
    {
        if (count($items) < 1) {
            throw ValidationException::withMessages([
                'items' => 'حداقل یک ردیف کالا باید ثبت شود.',
            ]);
        }

        $allCodes = [];
        $codeFields = [];

        $normalized = collect($items)->map(function ($item, $index) use (&$allCodes, &$codeFields) {
            $name = trim((string) ($item['item_name'] ?? ''));
            $quantity = (int) ($item['quantity'] ?? 0);
            $codesInput = (string) ($item['asset_codes_input'] ?? '');
            $description = trim((string) ($item['description'] ?? ''));
            $codesAttribute = "items.{$index}.asset_codes_input";

            if ($name === '') {
                throw ValidationException::withMessages([
                    "items.{$index}.item_name" => 'نام کالا در این ردیف الزامی است.',
                ]);
            }

            if ($quantity < 1) {
                throw ValidationException::withMessages([
                    "items.{$index}.quantity" => 'تعداد کالا باید حداقل ۱ باشد.',
                ]);
            }

            $codes = $this->assetCodeService->parseCodes($codesInput);
            $this->assetCodeService->validateFourDigitCodes($codes, $codesAttribute);

            if (count($codes) !== $quantity) {
                throw ValidationException::withMessages([
                    $codesAttribute => 'تعداد کدهای اموال باید با تعداد کالا برابر باشد.',
                ]);
            }

            foreach ($codes as $code) {
                if (isset($codeFields[$code])) {
                    throw ValidationException::withMessages([
                        $codesAttribute => "کد اموال {$code} در چند ردیف این سند تکرار شده است.",
                    ]);
                }

                $codeFields[$code] = $codesAttribute;
            }

            $allCodes = array_merge($allCodes, $codes);

            return [
                'item_name' => $name,
                'quantity' => $quantity,
                'description' => $description !== '' ? $description : null,
                'codes' => $codes,
            ];
        })->values()->all();

        $existingQuery = AssetDocumentItemCode::query()
            ->whereIn('asset_code', $allCodes);

        if ($excludeDocumentId) {
            $existingQuery->whereDoesntHave('item.document', fn ($query) => $query->whereKey($excludeDocumentId));
        }

        $existing = $existingQuery->pluck('asset_code')->all();

        if (!empty($existing)) {
            $code = (string) $existing[0];
            throw ValidationException::withMessages([
                $codeFields[$code] ?? 'items' => "کد اموال {$code} قبلاً در سیستم ثبت شده است.",
            ]);
        }

        return $normalized;
    }
}
