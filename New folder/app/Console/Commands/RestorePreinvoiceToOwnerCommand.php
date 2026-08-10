<?php

namespace App\Console\Commands;

use App\Models\PreinvoiceOrder;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class RestorePreinvoiceToOwnerCommand extends Command
{
    protected $signature = 'preinvoice:restore-to-owner
        {uuid : UUID پیش‌فاکتور}
        {--user-id= : شناسه کاربری مالک پیش‌فاکتور}
        {--status= : وضعیت جدید اختیاری برای پیش‌فاکتور}
        {--note= : توضیح ثبت‌شده در لاگ فعالیت}
        {--dry-run : فقط نمایش تغییرات بدون ذخیره}';

    protected $description = 'Restore a preinvoice into an operator\'s "my preinvoices" list by setting its owner and optional status.';

    public function handle(): int
    {
        $uuid = trim((string) $this->argument('uuid'));

        /** @var PreinvoiceOrder|null $order */
        $order = PreinvoiceOrder::query()
            ->with(['creator:id,name', 'invoice:id,uuid,preinvoice_order_id'])
            ->where('uuid', $uuid)
            ->first();

        if (! $order) {
            $this->error("پیش‌فاکتور با UUID {$uuid} پیدا نشد.");

            return self::FAILURE;
        }

        $userId = $this->resolveUserId($order);
        if ($userId === null) {
            $this->error('مالک فعلی خالی است؛ لطفاً گزینه --user-id را مشخص کنید.');

            return self::FAILURE;
        }

        $user = User::query()->select(['id', 'name'])->find($userId);
        if (! $user) {
            $this->error("کاربر با شناسه {$userId} پیدا نشد.");

            return self::FAILURE;
        }

        $status = $this->option('status') !== null ? trim((string) $this->option('status')) : null;
        if ($status !== null && $status !== '' && ! array_key_exists($status, PreinvoiceOrder::statusLabels())) {
            $this->error('وضعیت واردشده معتبر نیست. وضعیت‌های مجاز: ' . implode(', ', array_keys(PreinvoiceOrder::statusLabels())));

            return self::FAILURE;
        }

        $changes = [
            'created_by' => [
                'from' => $order->created_by,
                'to' => $userId,
            ],
        ];

        if ($status !== null && $status !== '') {
            $changes['status'] = [
                'from' => $order->status,
                'to' => $status,
            ];
        }

        $this->table(['field', 'from', 'to'], collect($changes)->map(fn (array $change, string $field) => [
            'field' => $field,
            'from' => $change['from'] ?? '—',
            'to' => $change['to'] ?? '—',
        ])->all());

        if ($this->option('dry-run')) {
            $this->warn('dry-run فعال است؛ تغییری ذخیره نشد.');

            return self::SUCCESS;
        }

        DB::transaction(function () use ($order, $userId, $status, $changes): void {
            $payload = ['created_by' => $userId];
            if ($status !== null && $status !== '') {
                $payload['status'] = $status;
            }

            $order->forceFill($payload)->save();

            $order->activityLogs()->create([
                'user_id' => null,
                'action' => 'restored_to_owner',
                'description' => $this->option('note') ?: 'بازگردانی پیش‌فاکتور به لیست پیش‌فاکتورهای من',
                'properties' => ['changes' => $changes],
                'occurred_at' => now(),
            ]);
        });

        $this->info("پیش‌فاکتور {$uuid} به لیست پیش‌فاکتورهای کاربر {$user->name} ({$user->id}) برگشت.");

        return self::SUCCESS;
    }

    private function resolveUserId(PreinvoiceOrder $order): ?int
    {
        $option = $this->option('user-id');
        if ($option !== null && $option !== '') {
            return (int) $option;
        }

        return $order->created_by !== null ? (int) $order->created_by : null;
    }
}
