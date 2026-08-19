<?php

namespace App\Actions\Customers;

use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class CreateCustomerAction
{
    public function execute(array $data): Customer
    {
        return DB::transaction(function () use ($data) {
            $customer = Customer::query()->create($this->customerData($data));
            $this->storePhones($customer, $data['phones'], (int) $data['primary_phone']);

            return $customer;
        });
    }

    private function storePhones(Customer $customer, array $phones, int $primary): void
    {
        foreach ($phones as $index => $phone) {
            $customer->phones()->create([
                'phone' => $phone['phone'],
                'is_primary' => $index === $primary,
            ]);
        }
    }

    private function customerData(array $data): array
    {
        $customerData = collect($data)->only(['name', 'company_name', 'city', 'address', 'notes', 'is_active'])->all();
        $customerData['mobile'] = $data['phones'][(int) $data['primary_phone']]['phone'];

        if (filled($data['password'] ?? null)) {
            $customerData['password'] = Hash::make((string) $data['password']);
            $customerData['password_changed_at'] = now();
        }

        return $customerData;
    }
}
