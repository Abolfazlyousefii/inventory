<?php

namespace App\Services;

use App\Models\{ActivityLog, Invoice, InvoiceItem, InvoiceNote, InvoicePayment};
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\{DB, Schema};

/** Read-only history for an invoice already authorized by its controller. */
class InvoiceTimelineService
{
    public function entries(Invoice $invoice): Collection
    {
        $invoice->loadMissing(['activityLogs.user:id,name']);
        $formatter = app(PreinvoiceTimelineService::class);
        $entries = collect();
        $logs = $invoice->activityLogs;
        if ($invoice->exists) {
            $children = ActivityLog::with('user:id,name')
                ->whereIn('subject_type', [InvoiceItem::class, InvoicePayment::class, InvoiceNote::class])
                ->where(function ($q) use ($invoice) {
                    foreach (['attributes', 'original', 'changes'] as $group) {
                        $q->orWhere('properties->'.$group.'->invoice_id', $invoice->id);
                    }
                    foreach ([InvoiceItem::class=>'items', InvoicePayment::class=>'payments', InvoiceNote::class=>'notes'] as $type=>$relation) {
                        $q->orWhere(fn($q)=>$q->where('subject_type', $type)->whereIn('subject_id', $invoice->$relation->modelKeys()));
                    }
                })->get();
            $logs = $logs->concat($children);
        }
        foreach ($logs as $log) {
            $entries->push(['time'=>$log->occurred_at ?? $log->created_at, 'actor'=>$log->user?->name ?? 'سیستم',
                'source'=>match($log->subject_type) {InvoiceItem::class=>'اقلام فاکتور', InvoicePayment::class=>'پرداخت', InvoiceNote::class=>'یادداشت', default=>'فاکتور فروش'},
                'title'=>$log->description ?: 'عملیات ثبت‌شده: '.str_replace('_', ' ', $log->action ?? ''),
                'details'=>$formatter->flatten(is_array($log->properties) ? $log->properties : [])]);
        }
        if ($invoice->preinvoiceOrder) {
            $order = $invoice->preinvoiceOrder;
            $order->loadMissing(['activityLogs.user:id,name', 'reviews.user:id,name', 'warehouseReviewItemLogs.user:id,name', 'items']);
            foreach ($formatter->entries($order) as $entry) {
                $entry['source'] = 'پیش‌فاکتور '.$order->uuid.' / '.$entry['source'];
                $entries->push($entry);
            }
        }
        if ($invoice->exists && Schema::hasTable('invoice_collection_revisions') && Schema::hasTable('invoice_collection_revision_items')) {
            $revisions = DB::table('invoice_collection_revisions as r')->leftJoin('users as u', 'u.id', '=', 'r.changed_by')
                ->where('r.invoice_id', $invoice->id)->select('r.*', 'u.name as actor')->get();
            $items = DB::table('invoice_collection_revision_items')->whereIn('invoice_collection_revision_id', $revisions->pluck('id'))->get()->groupBy('invoice_collection_revision_id');
            foreach ($revisions as $revision) {
                $details = array_filter([$revision->reason_note]);
                foreach ($items->get($revision->id, collect()) as $item) {
                    $details[] = trim(($item->product_name_snapshot ?? '').' / '.($item->variant_name_snapshot ?? ''));
                    $details[] = ['removed'=>'ردیف حذف شد', 'added'=>'ردیف اضافه شد', 'multiple_changes'=>'ردیف ویرایش شد'][$item->change_type] ?? 'اصلاح ردیف';
                    foreach (['quantity'=>'تعداد', 'price'=>'قیمت واحد', 'discount'=>'تخفیف', 'line_total'=>'جمع ردیف'] as $key=>$label) {
                        $old=$item->{'old_'.$key}; $new=$item->{'new_'.$key};
                        if ($old !== $new) $details[]=$label.': '.($old ?? '—').' ← '.($new ?? '—');
                    }
                }
                $entries->push(['time'=>Carbon::parse($revision->created_at), 'actor'=>$revision->actor ?: 'سیستم',
                    'source'=>'اصلاح اقلام انبار', 'title'=>'اصلاح فاکتور، نسخهٔ '.$revision->revision_number, 'details'=>$details]);
            }
        }
        return $entries->sortByDesc(fn($entry)=>$entry['time']?->getTimestamp() ?? 0)->values();
    }
}
