<?php

namespace App\Console\Commands;

use App\Services\CancelledInvoiceReissueService;
use Illuminate\Console\Command;
use Throwable;

class ReissueCancelledInvoiceAsDraftCommand extends Command
{
    protected $signature = 'invoices:reissue-cancelled-as-draft
        {invoice-number : شماره دقیق فاکتور لغوشده، شامل صفر ابتدایی}
        {--seller-id= : شناسه مالک پیش‌نویس}
        {--invoice-id= : شناسه داخلی فاکتور برای تطبیق ایمن}
        {--receipt-id= : شناسه رسید تأییدشده برگشت انبار}
        {--note= : دلیل بازصدور برای تاریخچه}
        {--apply : اعمال تغییرات؛ در حالت پیش‌فرض فقط پیش‌نمایش ارائه می‌شود}';

    protected $description = 'Create a stock-free seller draft from a cancelled invoice with finalized warehouse return; retain original records and archive from the default cancelled list.';

    public function handle(CancelledInvoiceReissueService $service): int
    {
        $number = trim((string) $this->argument('invoice-number'));
        $sellerId = (int) $this->option('seller-id');
        $invoiceId = (int) $this->option('invoice-id');
        $receiptId = (int) $this->option('receipt-id');

        if (! preg_match('/^\d{5}$/', $number) || $sellerId <= 0 || $invoiceId <= 0 || $receiptId <= 0) {
            $this->error('شماره پنج‌رقمی دقیق و تمام گزینه‌های --seller-id، --invoice-id و --receipt-id الزامی هستند.');

            return self::FAILURE;
        }

        try {
            $preview = $service->inspect($number, $sellerId, $invoiceId, $receiptId);
            $this->table(['مورد', 'مقدار'], [
                ['فاکتور لغوشده', $preview['invoice']->uuid.' (ID '.$preview['invoice']->id.')'],
                ['پیش‌فاکتور قبلی', $preview['old_order']->uuid.' (ID '.$preview['old_order']->id.')'],
                ['فروشنده', $preview['seller']->name.' (ID '.$sellerId.')'],
                ['رسید برگشت انبار', $preview['receipt']->receipt_number.' (ID '.$receiptId.')'],
                ['تعداد دریافت‌شده', (string) $preview['quantity']],
                ['رزرو موجودی جدید', 'صفر'],
            ]);

            if (! $this->option('apply')) {
                $this->warn('پیش‌نمایش: هیچ تغییری در دیتابیس اعمال نشد. برای اجرا گزینه --apply را اضافه کنید.');

                return self::SUCCESS;
            }

            $draft = $service->createDraft($number, $sellerId, $invoiceId, $receiptId, $this->option('note'));
            $this->info('پیش‌نویس جدید '.$draft->uuid.' (ID '.$draft->id.') ایجاد شد و به فهرست پیش‌نویس‌های فروشنده رفت.');
            $this->info('فاکتور '.$number.' همچنان لغوشده و دارای سوابق انباری است و در تب بازصدور‌شده‌ها نمایش داده می‌شود.');
            $this->line('آدرس ویرایش پیش‌نویس: '.route('preinvoice.draft.edit', $draft->uuid));

            return self::SUCCESS;
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());
            report($exception);

            return self::FAILURE;
        }
    }
}
