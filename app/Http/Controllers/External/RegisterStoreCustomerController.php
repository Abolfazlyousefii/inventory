<?php

namespace App\Http\Controllers\External;

use App\Http\Controllers\Controller;
use App\Models\Customer;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class RegisterStoreCustomerController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $this->authorizeRequest($request);

        $data = $request->validate([
            'crm_user_id' => ['required', 'string', 'max:255'],
            'name' => ['nullable', 'string', 'max:255'],
            'first_name' => ['nullable', 'string', 'max:255'],
            'last_name' => ['nullable', 'string', 'max:255'],
            'mobile' => ['nullable', 'string', 'max:20', 'required_without_all:phone,username'],
            'phone' => ['nullable', 'string', 'max:20'],
            'username' => ['nullable', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'status' => ['nullable', 'boolean'],
            'created_at' => ['nullable', 'date'],
            'updated_at' => ['nullable', 'date'],
        ]);

        $mobile = $this->normalizeMobile($data['mobile'] ?? $data['phone'] ?? $data['username'] ?? '');

        if ($mobile === null) {
            throw ValidationException::withMessages([
                'mobile' => 'شماره موبایل معتبر نیست.',
            ]);
        }

        [$firstName, $lastName] = $this->resolveName($data);

        [$customer, $created] = DB::transaction(function () use ($data, $mobile, $firstName, $lastName): array {
            $customer = Customer::query()
                ->where('crm_customer_id', $data['crm_user_id'])
                ->orWhere('mobile', $mobile)
                ->lockForUpdate()
                ->first();

            $created = $customer === null;
            $customer ??= new Customer();

            $customer->fill([
                'crm_customer_id' => $data['crm_user_id'],
                'sync_source' => 'store_registration',
                'first_name' => $firstName ?: ($customer->first_name ?: 'بدون نام'),
                'last_name' => $lastName ?: $customer->last_name,
                'mobile' => $mobile,
                'synced_at' => now(),
                'crm_updated_at' => $data['updated_at'] ?? null,
                'last_crm_payload' => collect($data)->except('password_hash')->all(),
            ]);

            if ($created) {
                $customer->reservation_tier = 'new_or_low_purchase';
            }

            $customer->save();

            return [$customer, $created];
        });

        return response()->json([
            'message' => $created ? 'Customer created.' : 'Customer updated.',
            'customer_id' => $customer->id,
            'crm_user_id' => $customer->crm_customer_id,
        ], $created ? 201 : 200);
    }

    private function authorizeRequest(Request $request): void
    {
        $expectedToken = (string) config('services.external_sync.token');
        $providedToken = (string) ($request->header('X-CRM-Token') ?: $request->bearerToken());

        abort_if($expectedToken === '' || ! hash_equals($expectedToken, $providedToken), 401, 'Unauthenticated.');
    }

    private function normalizeMobile(string $value): ?string
    {
        $value = strtr($value, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
        $value = preg_replace('/\D+/', '', $value) ?? '';

        if (str_starts_with($value, '0098')) {
            $value = '0'.substr($value, 4);
        } elseif (str_starts_with($value, '98')) {
            $value = '0'.substr($value, 2);
        } elseif (strlen($value) === 10 && str_starts_with($value, '9')) {
            $value = '0'.$value;
        }

        return preg_match('/^09\d{9}$/', $value) ? $value : null;
    }

    private function resolveName(array $data): array
    {
        $firstName = trim((string) ($data['first_name'] ?? ''));
        $lastName = trim((string) ($data['last_name'] ?? ''));

        if ($firstName === '' && trim((string) ($data['name'] ?? '')) !== '') {
            $parts = preg_split('/\s+/', trim($data['name']), 2) ?: [];
            $firstName = $parts[0] ?? '';
            $lastName = $lastName ?: ($parts[1] ?? '');
        }

        return [$firstName, $lastName ?: null];
    }
}
