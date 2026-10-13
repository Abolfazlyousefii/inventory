<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Services\Tracking\CustomerTrackingLinks;
use Illuminate\Console\Command;

final class GenerateCustomerTrackingLink extends Command
{
    protected $signature = 'tracking:link {customer_id : Existing customer ID} {--days=30 : Link lifetime, 1 to 90 days}';

    protected $description = 'Generate a time-limited customer invoice tracking link (do not share publicly).';

    public function handle(CustomerTrackingLinks $links): int
    {
        $customer = Customer::find($this->argument('customer_id'));
        if (! $customer || ! $customer->is_active) {
            $this->error('Active customer not found.');
            return self::FAILURE;
        }

        $days = filter_var($this->option('days'), FILTER_VALIDATE_INT);
        if ($days === false || $days < 1 || $days > 90) {
            $this->error('--days must be an integer between 1 and 90.');
            return self::FAILURE;
        }

        $this->line($links->create($customer, $days));

        return self::SUCCESS;
    }
}
