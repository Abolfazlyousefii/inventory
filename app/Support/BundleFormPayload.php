<?php

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/** A single JSON form value avoids PHP max_input_vars truncating large recipes. */
final class BundleFormPayload
{
    public static function merge(Request $request, array $fields): void
    {
        if (! $request->has('bundle_form_payload')) return;
        $raw = $request->input('bundle_form_payload');
        try {
            if (! is_string($raw) || strlen($raw) > 2_000_000) throw new \InvalidArgumentException;
            $decoded = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
            if (! is_array($decoded) || $decoded === [] || array_diff(array_keys($decoded), $fields)) {
                throw new \InvalidArgumentException;
            }
            foreach ($decoded as $rows) {
                if (! is_array($rows) || count($rows) > BundleRecipe::MAX_COMPONENTS) throw new \InvalidArgumentException;
            }
        } catch (\JsonException|\InvalidArgumentException $exception) {
            throw ValidationException::withMessages(['components' => 'اطلاعات اقلام بسته کامل یا معتبر نیست. صفحه را بررسی و دوباره ارسال کنید.']);
        }
        // Existing row validation, inventory permissions and transactions still apply.
        $request->merge($decoded);
    }
}
