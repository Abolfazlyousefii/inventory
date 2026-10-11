<?php

namespace App\Services;

use App\Models\{ActivityLog, PreinvoiceOrder, PreinvoiceOrderItem};
use Illuminate\Support\Collection;

class PreinvoiceTimelineService
{
    private const LABELS = [
        'attributes'=>'اطلاعات ثبت‌شده', 'changes'=>'تغییرات', 'old'=>'مقادیر قبلی', 'original'=>'مقادیر قبلی',
        'new'=>'مقادیر جدید', 'before'=>'قبل', 'after'=>'بعد', 'items'=>'اقلام', 'status'=>'وضعیت',
        'quantity'=>'تعداد', 'price'=>'قیمت', 'customer_name'=>'نام مشتری', 'customer_mobile'=>'موبایل مشتری',
        'customer_address'=>'آدرس مشتری', 'description'=>'توضیحات', 'reason'=>'دلیل', 'note'=>'یادداشت',
        'discount'=>'تخفیف', 'discount_value'=>'مقدار تخفیف', 'discount_type'=>'نوع تخفیف', 'shipping_price'=>'هزینه ارسال',
        'product_id'=>'شناسه کالا', 'variant_id'=>'شناسه تنوع', 'product_variant_id'=>'شناسه تنوع',
        'product_name'=>'نام کالا', 'variant_name'=>'نام تنوع', 'seller_id'=>'شناسه فروشنده', 'user_id'=>'شناسه کاربر',
        'created_by'=>'ثبت‌کننده', 'invoice_id'=>'شناسه فاکتور', 'uuid'=>'شماره سند', 'id'=>'شناسه',
        'stock_frozen_until'=>'مهلت رزرو', 'stock_released_at'=>'زمان آزادسازی', 'line_discount_amount'=>'تخفیف ردیف',
        'total'=>'جمع', 'total_price'=>'مبلغ کل', 'grand_total'=>'مبلغ نهایی', 'shipping_id'=>'روش ارسال',
        'approved_quantity'=>'تعداد تأییدشده', 'old_quantity'=>'تعداد قبلی', 'new_quantity'=>'تعداد جدید',
        'old_price'=>'قیمت قبلی', 'new_price'=>'قیمت جدید', 'is_active'=>'فعال', 'created_at'=>'زمان ثبت', 'updated_at'=>'زمان ویرایش',
    ];

    private const ACTIONS = [
        'created'=>'ثبت سند', 'updated'=>'ویرایش سند', 'deleted'=>'حذف', 'approved'=>'تأیید', 'rejected'=>'رد',
        'preinvoice_draft_saved'=>'ذخیره پیش‌نویس', 'preinvoice_draft_updated'=>'ویرایش پیش‌نویس',
        'preinvoice_submitted'=>'ثبت نهایی پیش‌فاکتور', 'finance_edited'=>'ویرایش مالی',
        'finance_returned_preinvoice'=>'ارجاع مالی به فروشنده', 'finance_cancelled_preinvoice'=>'لغو توسط مالی',
        'warehouse_approved'=>'تأیید انبار', 'finance_approved'=>'تأیید مالی', 'quantity_changed'=>'اصلاح تعداد',
        'warehouse_saved'=>'ذخیرهٔ بررسی انبار', 'warehouse_rejected'=>'رد توسط انبار',
        'seller_submitted_to_finance'=>'ارسال فروشنده به مالی', 'before_items'=>'اقلام قبل از تغییر', 'after_items'=>'اقلام بعد از تغییر',
        'price_changed'=>'اصلاح قیمت', 'removed'=>'حذف ردیف', 'added'=>'افزودن ردیف', 'cancelled'=>'لغو',
        'returned'=>'ارجاع برای اصلاح', 'stock_released'=>'آزادسازی رزرو', 'reservation_expired'=>'انقضای رزرو',
    ];

    public function entries(PreinvoiceOrder $order): Collection
    {
        $entries = collect();
        $logs = $order->activityLogs;
        if ($order->exists && $order->getKey()) {
            // Also retain audit records for item rows already deleted from the order.
            $itemLogs = ActivityLog::with('user:id,name')->where('subject_type', (new PreinvoiceOrderItem())->getMorphClass())
                ->where(function ($q) use ($order) {
                    $q->whereIn('subject_id', $order->items->modelKeys());
                    foreach (['attributes', 'original', 'changes'] as $group) {
                        $q->orWhere('properties->'.$group.'->preinvoice_order_id', $order->getKey());
                    }
                })->get();
            $logs = $logs->concat($itemLogs);
        }
        foreach ($logs as $log) {
            $entries->push(['time'=>$log->occurred_at ?? $log->created_at, 'actor'=>$log->user?->name ?? 'سیستم',
                'source'=>'سوابق سند', 'title'=>$log->description ?: $this->action($log->action),
                'details'=>$this->flatten(is_array($log->properties) ? $log->properties : [])]);
        }
        foreach ($order->reviews as $review) {
            $details = array_filter([$review->reason]);
            foreach (['before_items'=>'اقلام قبل از تغییر', 'after_items'=>'اقلام بعد از تغییر'] as $field=>$label) {
                if (is_array($review->$field)) $details = array_merge($details, $this->flatten($review->$field, $label));
            }
            $entries->push(['time'=>$review->created_at, 'actor'=>$review->user?->name ?? 'سیستم',
                'source'=>'بازبینی انبار / مالی', 'title'=>$this->action($review->action), 'details'=>$details]);
        }
        foreach ($order->warehouseReviewItemLogs as $log) {
            $details = array_filter([$log->product_name_snapshot, $log->variant_name_snapshot, $log->reason, $log->note]);
            foreach (['quantity'=>'تعداد', 'price'=>'قیمت'] as $field=>$label) {
                $old = $log->{'old_'.$field}; $new = $log->{'new_'.$field};
                if ($old !== null || $new !== null) $details[] = $label.' از '.($old ?? '—').' به '.($new ?? '—');
            }
            if ($log->approved_quantity !== null) $details[] = 'تعداد تأییدشده: '.$log->approved_quantity;
            $entries->push(['time'=>$log->created_at, 'actor'=>$log->user?->name ?? 'سیستم', 'source'=>'بررسی اقلام انبار',
                'title'=>$this->action($log->action), 'details'=>$details]);
        }
        return $entries->sortByDesc(fn ($e)=>$e['time']?->getTimestamp() ?? 0)->values();
    }

    private function action(?string $action): string
    {
        return self::ACTIONS[$action ?? ''] ?? ($action ? 'عملیات ثبت‌شده: '.str_replace(['_', '.'], ' ', $action) : 'بازبینی سند');
    }

    public function flatten(array $values, string $prefix = ''): array
    {
        $lines = [];
        foreach ($values as $key=>$value) {
            $label = is_int($key) ? 'ردیف '.($key + 1) : (self::LABELS[$key] ?? str_replace(['_', '.'], ' ', $key));
            $path = $prefix ? $prefix.' / '.$label : $label;
            if (is_array($value)) {
                $lines = array_merge($lines, $value ? $this->flatten($value, $path) : [$path.': خالی']);
            } else {
                $text = is_bool($value) ? ($value ? 'بله' : 'خیر') : ($value === null ? '—' : (string) $value);
                if ($key === 'status') $text = PreinvoiceOrder::statusLabels()[$text] ?? $text;
                $lines[] = $path.': '.$text;
            }
        }
        return $lines;
    }
}
