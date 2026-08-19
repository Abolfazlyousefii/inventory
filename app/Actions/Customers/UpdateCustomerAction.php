<?php

namespace App\Actions\Customers;

use App\Models\Customer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UpdateCustomerAction
{
    public function execute(Customer $customer, array $data): Customer
    {
        return DB::transaction(function () use ($customer, $data) {
            $customerData = collect($data)->only(['name', 'company_name', 'city', 'address', 'notes', 'is_active'])->all();
            $customerData['mobile'] = $data['phones'][(int) $data['primary_phone']]['phone'];

            if (filled($data['password'] ?? null)) {
                $customerData['password'] = Hash::make((string) $data['password']);
                $customerData['password_changed_at'] = now();
            }

            $customer->update($customerData);
            $customer->phones()->delete();

            foreach ($data['phones'] as $index => $phone) {
                $customer->phones()->create([
                    'phone' => $phone['phone'],
                    'is_primary' => $index === (int) $data['primary_phone'],
                ]);
            }

            return $customer;
        });
    }
}
