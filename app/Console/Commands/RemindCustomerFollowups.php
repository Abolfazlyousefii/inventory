<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\CustomerFollowup;
use App\Services\CustomerWorkspace;
use App\Services\NotificationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Symfony\Component\HttpKernel\Exception\HttpException;

class RemindCustomerFollowups extends Command
{
    protected $signature = 'commercial:followups-remind';

    protected $description = 'Create one internal reminder for each due customer followup and current assignment';

    public function handle(): int
    {
        $sent = 0;
        CustomerFollowup::where('status', 'open')->whereNull('reminded_at')->where('due_at', '<=', now())->select('id', 'customer_id')->chunkById(100, function ($rows) use (&$sent) {
            foreach ($rows as $row) {
                DB::transaction(function () use ($row, &$sent) {
                    $customer = Customer::whereKey($row->customer_id)->lockForUpdate()->first();
                    $task = CustomerFollowup::whereKey($row->id)->lockForUpdate()->first();
                    if (! $customer?->is_active || ! $task || $task->status !== 'open' || $task->reminded_at || $task->due_at->isFuture()) {
                        return;
                    }
                    $owner = $customer->assignment()->with('seller')->first()?->seller;
                    if (! $owner || $owner->id !== $task->assigned_to || ! $owner->is_active || ! $owner->can_access_erp) {
                        return;
                    }
                    try {
                        app(CustomerWorkspace::class)->authorize($owner);
                    } catch (HttpException $e) {
                        if ($e->getStatusCode() === 403) {
                            return;
                        }throw $e;
                    }
                    app(NotificationService::class)->notifyUser($owner, 'customer_followup_due', 'زمان پیگیری مشتری رسید', $customer->display_name.' — '.$task->title, route('commercial.customers.show', [$customer, 'tab' => 'activity']), [
                        'level' => 'warning', 'priority' => 'important', 'notifiable_type' => CustomerFollowup::class, 'notifiable_id' => $task->id, 'unique_key' => 'customer-followup:'.$task->id.':'.$task->version,
                    ]);
                    $task->update(['reminded_at' => now()]);
                    $sent++;
                });
            }
        });
        $this->info('Internal reminders created: '.$sent);

        return self::SUCCESS;
    }
}
