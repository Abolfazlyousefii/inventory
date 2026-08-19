<?php

namespace Database\Factories;

use App\Models\Customer;
use App\Models\CustomerPhone;
use Illuminate\Database\Eloquent\Factories\Factory;

class CustomerPhoneFactory extends Factory
{
    protected $model = CustomerPhone::class;

    public function definition(): array
    {
        return [
            'customer_id' => Customer::factory(),
            'phone' => '09'.fake()->unique()->numerify('#########'),
            'is_primary' => false,
        ];
    }
}
