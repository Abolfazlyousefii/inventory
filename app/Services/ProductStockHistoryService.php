<?php

namespace App\Services;

use App\Models\{Invoice, Purchase, StockMovement, WarehouseTransfer};
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/** Read-only history: stock events and their supporting documents are separate facts. */
class ProductStockHistoryService
{
    public function history(int $productId, ?int $variantId = null): array
    {
        $rows = collect();
        $direct = StockMovement::where('product_id', $productId)
            ->when($variantId, fn($q) => $q->where('product_variant_id', $variantId))
            ->with(['variant:id,product_id,variant_name,variant_code', 'warehouse:id,name', 'user:id,name'])->get();
        foreach ($direct as $movement) {
            $movement->history_kind = 'ثبت مستقیم موجودی';
            $rows->push($movement);
        }
        $central = DB::table('warehouses')->where('type', 'central')->value('id');
        $reservations = DB::table('preinvoice_draft_reservations as r')
            ->leftJoin('preinvoice_orders as p', 'p.id', '=', 'r.preinvoice_order_id')
            ->where('r.product_id', $productId)->when($variantId, fn($q) => $q->where('r.variant_id', $variantId))
            ->select('r.*', 'p.uuid as order_uuid')->get();
        foreach ($reservations as $r) {
            if ((int) $r->quantity <= 0) continue;
            $reference = $r->order_uuid ? 'پیش‌فاکتور '.$r->order_uuid : 'رزرو #'.$r->id;
            $url = $r->order_uuid ? route('preinvoice.my.show', $r->order_uuid) : null;
            // A reservation row is mutable: its remaining quantity is not a full delta log.
            $rows->push($this->row('reserve-'.$r->id, $r->created_at, $r->variant_id, $central,
                'document_out', 'رزرو؛ کسر از موجودی آزاد', $r->quantity, $reference, $url, $r->user_id,
                'مقدار ثبت‌شدهٔ رزرو؛ افزایش و کاهش‌های میانی ممکن است در این رکورد نگهداری نشده باشند.', 'سابقه رزرو'));
            if ($r->released_at) {
                $consumed = in_array($r->release_reason, ['consumed', 'converted_invoice_cleanup'], true);
                $neutral = in_array($r->release_reason, ['legacy_cleanup', 'historical_reconciliation_stock_neutral'], true);
                $knownReturn = in_array($r->release_reason, ['save_as_draft','manual_release','temporary_session_lost','submitted_to_finance','expired','reservation_expired','cancelled','returned_to_sales','cancelled_by_finance','cancelled_by_warehouse','draft_discarded','abandoned','token_release'], true);
                $type = !$consumed && !$neutral && $knownReturn ? 'document_in' : 'info';
                $label = $consumed ? 'مصرف رزرو در فاکتور؛ بدون کسر مجدد' : ($neutral ? 'بستن رزرو؛ بدون برگشت موجودی' : ($knownReturn ? 'آزادسازی رزرو؛ برگشت موجودی آزاد' : 'پایان رزرو؛ اثر موجودی نامشخص'));
                $rows->push($this->row('release-'.$r->id, $r->released_at, $r->variant_id, $central,
                    $type, $label, $r->quantity, $reference, $url, $r->released_by,
                    trim(($r->release_note ?? '').' | علت: '.($r->release_reason ?? 'نامشخص')), 'سابقه رزرو'));
            }
        }
        $sales = DB::table('invoice_items as ii')->join('invoices as i', 'i.id', '=', 'ii.invoice_id')
            ->where('ii.product_id', $productId)->when($variantId, fn($q) => $q->where('ii.variant_id', $variantId))
            ->get(['ii.id','ii.variant_id','ii.quantity','i.id as invoice_id','i.uuid','i.status','i.cancelled_at','i.preinvoice_order_id','i.created_at','i.seller_id']);
        $sold = 0;
        foreach ($sales as $sale) {
            $cancelled = $sale->cancelled_at || in_array($sale->status, Invoice::cancelledStatuses(), true);
            if (!$cancelled) $sold += (int) $sale->quantity;
            // Always show the invoice, but never add its quantity to stock deltas:
            // stock may have been deducted at reservation or by another recorded event.
            $linked = $direct->contains(fn($m) => $m->reference_type === Invoice::class && (int)$m->reference_id === (int)$sale->invoice_id)
                || $reservations->contains(fn($r) => $sale->preinvoice_order_id && (int)$r->preinvoice_order_id === (int)$sale->preinvoice_order_id && (int)$r->variant_id === (int)$sale->variant_id && in_array($r->release_reason, ['consumed','converted_invoice_cleanup'], true));
            $rows->push($this->row('invoice-item-'.$sale->id, $sale->created_at, $sale->variant_id, $central,
                $cancelled ? 'info' : 'document_out', $cancelled ? 'فاکتور لغوشده؛ مرجع فروش' : 'فروش؛ '.$this->statusLabel($sale->status), $sale->quantity,
                'فاکتور '.$sale->uuid, route('invoices.show', $sale->uuid), $sale->seller_id,
                $linked ? 'سند فروش مرتبط با رزرو یا گردش موجودی؛ این تعداد دوباره از موجودی کم نمی‌شود.' : 'سند فروش ثبت شده است؛ زمان و مقدار دقیق کسر موجودی در سابقهٔ مستقل موجود نیست.', 'سند فروش'));
        }
        $purchases = DB::table('purchase_items as pi')->join('purchases as p', 'p.id', '=', 'pi.purchase_id')
            ->where('pi.product_id', $productId)->when($variantId, fn($q) => $q->where('pi.product_variant_id', $variantId))
            ->get(['pi.id','pi.product_variant_id','pi.quantity','p.id as purchase_id','p.created_at','p.user_id']);
        foreach ($purchases as $purchase) {
            $covered = $direct->contains(fn($m) => (int)$m->product_variant_id === (int)$purchase->product_variant_id &&
                (($m->reference_type === Purchase::class && (int)$m->reference_id === (int)$purchase->purchase_id) || in_array($m->reference, ['PUR-'.$purchase->purchase_id, 'PUR-ADJ-'.$purchase->purchase_id], true)));
            if (!$covered) $rows->push($this->row('purchase-item-'.$purchase->id, $purchase->created_at, $purchase->product_variant_id, $central,
                'document_in', 'خرید؛ سند بدون گردش مستقل', $purchase->quantity, 'خرید #'.$purchase->purchase_id,
                route('purchases.show', $purchase->purchase_id), $purchase->user_id, 'تعداد فعلی سند خرید؛ زمان و تغییرات میانی موجودی در سابقهٔ مستقل موجود نیست.', 'سند خرید'));
        }
        $this->transfers($rows, $direct, $productId, $variantId);
        $this->stocktakes($rows, $direct, $productId, $variantId);
        $this->invoiceRevisions($rows, $productId, $variantId, $central);
        $this->inboundReceipts($rows, $direct, $productId, $variantId);
        $this->salesReturnDocuments($rows, $productId, $variantId);
        $this->explainEffects($rows);
        $this->hydrate($rows);
        $stocks = DB::table('warehouse_stocks')->where('warehouse_id', $central)->where('product_id', $productId);
        // Aggregate-only rows duplicate variant quantities; use variants when present.
        if ($variantId) $stocks->where('product_variant_id', $variantId);
        elseif (DB::table('product_variants')->where('product_id', $productId)->exists()) $stocks->whereNotNull('product_variant_id');
        $current = $central ? (int) $stocks->sum('quantity') : null;
        $variants = DB::table('product_variants')->where('product_id', $productId)
            ->when($variantId, fn ($q) => $q->where('id', $variantId))->get(['stock', 'reserved']);
        $cached = $variants->isNotEmpty() ? (int) $variants->sum('stock') : null;
        return ['rows' => $rows->sort(function($a, $b) { return strcmp((string)$b->created_at, (string)$a->created_at) ?: strcmp((string)$b->id, (string)$a->id); })->values(),
            'sold' => $sold, 'current' => $current, 'cached_current' => $cached,
            'reserved' => (int) $variants->sum('reserved'),
            // This checks the current projection only, not an opening-to-closing ledger.
            'projection_matches' => $current !== null && $cached !== null && $current === $cached,
            'history_complete' => false,
            'incomplete_records' => $rows->where('evidence_level', 'incomplete')->count()];
    }

    private function invoiceRevisions(Collection $rows, int $product, ?int $variant, ?int $central): void
    {
        $items = DB::table('invoice_collection_revision_items as ci')
            ->join('invoice_collection_revisions as c', 'c.id', '=', 'ci.invoice_collection_revision_id')
            ->join('invoices as i', 'i.id', '=', 'c.invoice_id')
            ->where('ci.product_id', $product)->when($variant, fn ($q) => $q->where('ci.product_variant_id', $variant))
            ->get(['ci.id','ci.product_variant_id','ci.old_quantity','ci.new_quantity','ci.change_type',
                'ci.variant_name_snapshot','ci.sku_snapshot','c.invoice_id','c.changed_by','c.reason_note','c.created_at','i.uuid']);
        foreach ($items as $item) {
            $before = (int) $item->old_quantity;
            $after = (int) $item->new_quantity;
            if ($before === $after) continue; // Price-only revisions do not explain stock changes.
            $label = $after === 0 ? 'حذف قلم از فاکتور' : ($after < $before ? 'کاهش تعداد قلم فاکتور' : 'افزایش تعداد قلم فاکتور');
            $row = $this->row('revision-item-'.$item->id, $item->created_at, $item->product_variant_id, $central,
                'info', $label, abs($after - $before), 'فاکتور '.$item->uuid, route('invoices.show', $item->uuid),
                $item->changed_by, trim(($item->reason_note ?? '').' | تغییر تعداد سند به‌تنهایی دریافت انبار نیست؛ نتیجهٔ رسید ورودی را بررسی کنید.'), 'اصلاح سند فروش');
            $row->document_quantity_before = $before;
            $row->document_quantity_after = $after;
            $row->variant_snapshot = $item->variant_name_snapshot;
            $row->variant_code_snapshot = $item->sku_snapshot;
            $row->available_delta = 0; // Supporting document; never counted again.
            $rows->push($row);
        }
    }

    private function inboundReceipts(Collection $rows, Collection $direct, int $product, ?int $variant): void
    {
        $items = DB::table('warehouse_inbound_receipt_items as ri')
            ->join('warehouse_inbound_receipts as r', 'r.id', '=', 'ri.receipt_id')
            ->where('ri.product_id', $product)->when($variant, fn ($q) => $q->where('ri.product_variant_id', $variant))
            ->get(['ri.id','ri.product_variant_id','ri.expected_quantity','ri.accepted_quantity',
                'ri.suggested_warehouse_id','ri.received_warehouse_id','ri.stock_movement_id','ri.reason','ri.note',
                'r.id as receipt_id','r.receipt_number','r.source_type','r.source_id','r.status','r.created_at',
                'r.reviewed_at','r.requested_by','r.reviewed_by','r.review_note']);
        $invoiceIds = $items->whereIn('source_type', ['invoice_adjustment','invoice_cancel','finance_adjustment'])->pluck('source_id')->unique();
        $invoices = DB::table('invoices')->whereIn('id', $invoiceIds)->pluck('uuid', 'id');
        $movementRows = $direct->keyBy('id');
        foreach ($items as $item) {
            $final = in_array($item->status, ['received', 'discrepancy'], true);
            $accepted = $final ? (int) $item->accepted_quantity : 0;
            $expected = (int) $item->expected_quantity;
            $label = match ($item->status) {
                'pending' => 'در انتظار دریافت انبار',
                'cancelled' => 'رسید ورودی لغوشده',
                default => $accepted === 0 ? 'دریافت صفر؛ موجودی اضافه نشده' : ($item->status === 'discrepancy' ? 'دریافت انبار با مغایرت' : 'دریافت تأییدشدهٔ انبار'),
            };
            $row = $this->row('inbound-item-'.$item->id, $final ? ($item->reviewed_at ?: $item->created_at) : $item->created_at,
                $item->product_variant_id, $item->received_warehouse_id ?: $item->suggested_warehouse_id,
                'info', $label, $expected, $item->receipt_number, route('warehouse.inbound.show', $item->receipt_id),
                $final ? $item->reviewed_by : $item->requested_by,
                trim(($item->note ?? '').' | '.($item->review_note ?? '')),
                'نتیجهٔ رسید ورودی');
            $row->expected_quantity = $expected;
            $row->accepted_quantity = $accepted;
            $row->event_status = $item->status;
            $row->available_delta = 0; // The linked movement is the numeric event.
            $row->related_reference = null;
            $row->related_url = null;
            if ($invoices->has($item->source_id) && in_array($item->source_type, ['invoice_adjustment','invoice_cancel','finance_adjustment'], true)) {
                $row->related_reference = 'فاکتور '.$invoices[$item->source_id];
                $row->related_url = route('invoices.show', $invoices[$item->source_id]);
            }
            $row->evidence_note = $final
                ? ($accepted === 0 ? 'از تعداد مورد انتظار، هیچ کالایی به موجودی تحویل نشده است.' : 'تعداد دریافت‌شده در گردش مرتبط ثبت می‌شود؛ این ردیف دوباره جمع نمی‌شود.')
                : ($item->status === 'cancelled' ? 'این رسید اثر دریافتی ندارد.' : 'تا تأیید دریافت، مقداری از این رسید وارد موجودی نمی‌شود.');
            $linked = $movementRows->get($item->stock_movement_id);
            $validLink = $linked && $linked->type === 'in'
                && (int) $linked->product_variant_id === (int) $item->product_variant_id
                && (int) $linked->warehouse_id === (int) $item->received_warehouse_id
                && (int) $linked->quantity === $accepted;
            if ($final && $accepted > 0 && ! $validLink) {
                $row->evidence_level = 'incomplete';
                $row->evidence_note = 'دریافت مثبت ثبت شده، ولی گردش مطابق با تنوع، انبار و تعداد در این گزارش پیدا نشد؛ نیازمند بررسی است.';
            }
            // Give the actual numeric event a useful source even when old reference fields are absent.
            if ($final && $accepted > 0 && $validLink) {
                $movement = $linked;
                $movement->source_url = route('warehouse.inbound.show', $item->receipt_id);
                $movement->source_label = $item->receipt_number;
                $movement->related_reference = $row->related_reference;
                $movement->related_url = $row->related_url;
            }
            $rows->push($row);
        }
    }

    private function salesReturnDocuments(Collection $rows, int $product, ?int $variant): void
    {
        $items = DB::table('sales_return_document_items as ri')->join('sales_return_documents as r', 'r.id', '=', 'ri.document_id')
            ->where('ri.product_id', $product)->when($variant, fn ($q) => $q->where('ri.product_variant_id', $variant))
            ->get(['ri.id','ri.product_variant_id','ri.return_quantity','ri.item_condition','ri.destination_warehouse_id',
                'r.id as document_id','r.document_number','r.status','r.created_at','r.created_by','r.applied_at']);
        foreach ($items as $item) {
            $row = $this->row('sales-return-item-'.$item->id, $item->applied_at ?: $item->created_at, $item->product_variant_id,
                $item->destination_warehouse_id, 'info', 'سند برگشت از فروش؛ '.($item->item_condition === 'healthy' ? 'سالم' : 'مرجوعی / خراب'),
                $item->return_quantity, 'برگشت از فروش '.$item->document_number, route('sales-returns.show', $item->document_id), $item->created_by,
                'ثبت یا اعمال سند برگشت، به‌تنهایی ورود به موجودی آزاد مرکزی نیست؛ مقصد و نتیجهٔ دریافت را بررسی کنید.', 'سند برگشت از فروش');
            $row->available_delta = 0;
            $row->event_status = $item->status;
            $rows->push($row);
        }
    }

    private function explainEffects(Collection $rows): void
    {
        foreach ($rows as $row) {
            $row->snapshot_reliable = false;
            $row->reserved_delta = null; // Mutable reservation rows cannot prove historical deltas.
            if ($row instanceof StockMovement || ($row->history_kind === 'سند انبارگردانی' && $row->stock_before !== null && $row->stock_after !== null)) {
                $hasSnapshot = $row->stock_before !== null && $row->stock_after !== null;
                $signed = in_array($row->type, ['in','out'], true) ? (int) $row->quantity * ($row->type === 'in' ? 1 : -1) : 0;
                $valid = $hasSnapshot && (int) $row->stock_after - (int) $row->stock_before === $signed
                    && in_array($row->type, ['in','out','info'], true) && (int) $row->quantity >= 0
                    && $row->warehouse_id !== null && $row->product_variant_id !== null;
                $row->evidence_level = $valid ? 'recorded' : 'incomplete';
                $row->available_delta = $valid ? $signed : null;
                $row->snapshot_reliable = $valid;
                $row->effect_label = $valid ? ($signed > 0 ? 'افزایش موجودی همین انبار' : ($signed < 0 ? 'کاهش موجودی همین انبار' : 'بدون تغییر موجودی')) : 'اثر دقیق در سابقه اثبات نشده';
                $row->evidence_note = $valid ? 'قبل و بعد با تعداد این گردش تطبیق دارد؛ این به معنی تأیید قصد عملیات نیست.' : 'سابقهٔ قدیمی، انبار نامشخص یا قبل/بعد ناسازگار است؛ مقدار تاریخی حدس زده نمی‌شود.';
            } else {
                $reservation = $row->history_kind === 'سابقه رزرو';
                $row->evidence_level ??= $reservation ? 'incomplete' : 'reference';
                $row->available_delta = $reservation ? null : 0;
                $row->effect_label = $reservation ? 'سابقهٔ رزرو؛ تغییر مستقل اثبات نشده' : 'سند توضیحی؛ دوباره جمع نمی‌شود';
                $row->evidence_note ??= $reservation ? 'رکورد رزرو ممکن است چند بار ویرایش شده باشد؛ این تعداد را جمع جبری گردش نکنید.' : 'این ردیف سند و نتیجهٔ عملیات را توضیح می‌دهد؛ اثر عددی باید در گردش مستقل دیده شود.';
            }
            $row->evidence_label = match ($row->evidence_level) {
                'recorded' => 'گردش دارای قبل/بعد مطابق',
                'reference' => 'سند توضیحی',
                default => 'سابقهٔ ناقص / نیازمند بررسی',
            };
        }
    }

    private function row(string $id, mixed $date, ?int $variant, ?int $warehouse, string $type, string $label, int $quantity, string $reference, ?string $url, ?int $user, string $note, string $kind, ?int $before = null, ?int $after = null): object
    {
        return (object)['id'=>$id, 'created_at'=>$date ? Carbon::parse($date) : null, 'product_variant_id'=>$variant, 'warehouse_id'=>$warehouse,
            'user_id'=>$user, 'type'=>$type, 'reason'=>$label, 'quantity'=>$quantity, 'stock_before'=>$before, 'stock_after'=>$after,
            'reference'=>$reference, 'reference_type'=>null, 'reference_id'=>null, 'transaction_type'=>null, 'note'=>$note,
            'history_kind'=>$kind, 'source_url'=>$url, 'variant'=>null, 'warehouse'=>null, 'user'=>null];
    }

    private function hydrate(Collection $rows): void
    {
        $variants=DB::table('product_variants')->whereIn('id',$rows->pluck('product_variant_id')->filter()->unique())->get(['id','variant_name','variant_code'])->keyBy('id');
        $warehouses=DB::table('warehouses')->whereIn('id',$rows->pluck('warehouse_id')->filter()->unique())->get(['id','name'])->keyBy('id');
        $users=DB::table('users')->whereIn('id',$rows->pluck('user_id')->filter()->unique())->get(['id','name'])->keyBy('id');
        foreach($rows as $row) if(!($row instanceof StockMovement)) { $row->variant=$variants->get($row->product_variant_id); $row->warehouse=$warehouses->get($row->warehouse_id); $row->user=$users->get($row->user_id); }
    }

    private function transfers(Collection $rows, Collection $direct, int $product, ?int $variant): void
    {
        $items=DB::table('warehouse_transfer_items as ti')->join('warehouse_transfers as t','t.id','=','ti.warehouse_transfer_id')
            ->where('ti.product_id',$product)->when($variant,fn($q)=>$q->where('ti.product_variant_id',$variant))
            ->get(['ti.id','ti.product_variant_id','ti.quantity','ti.destination_warehouse_id','t.id as transfer_id','t.reference','t.voucher_type','t.from_warehouse_id','t.to_warehouse_id','t.transferred_at','t.created_at','t.user_id']);
        foreach($items as $item){
            $reference=$item->reference ?: 'حواله #'.$item->transfer_id;
            // Transfer documents describe direction, not necessarily their historical applied deltas.
            if($item->voucher_type==='customer_return') $legs=[['document_in',$item->destination_warehouse_id ?: $item->to_warehouse_id,'مرجوعی مشتری']];
            elseif($item->voucher_type==='between_warehouses') $legs=[['document_out',$item->from_warehouse_id,'انتقال از انبار'],['document_in',$item->to_warehouse_id,'انتقال به انبار']];
            else $legs=[['document_out',$item->from_warehouse_id,'حواله '.$item->voucher_type]];
            foreach($legs as $n=>[$type,$warehouse,$label]) {
                $covered=$direct->contains(fn($m)=>(($m->reference_type===WarehouseTransfer::class && (int)$m->reference_id===(int)$item->transfer_id) || $m->reference==='TR-'.$item->transfer_id || ($item->reference && $m->reference===$item->reference)) && ((int)$m->product_variant_id===(int)$item->product_variant_id || $m->product_variant_id===null) && ($m->warehouse_id===null || (int)$m->warehouse_id===(int)$warehouse) && $m->type===str_replace('document_','',$type));
                if(!$covered) $rows->push($this->row('transfer-'.$item->id.'-'.$n,$item->transferred_at ?: $item->created_at,$item->product_variant_id,$warehouse,$type,$label,$item->quantity,$reference,route('vouchers.show',$item->transfer_id),$item->user_id,'بر اساس سند حواله؛ مقدار قبل و بعد در سابقهٔ مستقل موجود نیست.','سند حواله'));
            }
        }
    }

    private function stocktakes(Collection $rows, Collection $direct, int $product, ?int $variant): void
    {
        $items=DB::table('stock_count_document_items as ci')->join('stock_count_documents as c','c.id','=','ci.document_id')
            ->where('ci.product_id',$product)->where('c.status','finalized')->when($variant,fn($q)=>$q->where('ci.product_variant_id',$variant))
            ->get(['ci.id','ci.document_id','ci.product_variant_id','ci.warehouse_id','ci.system_available_at_start','ci.new_available','c.document_number','c.finalized_at','c.finalized_by']);
        foreach($items as $item){
            if($direct->contains(fn($m)=>in_array($m->reference_type,['stock_count_document',\App\Models\StockCountDocument::class],true) && (int)$m->reference_id===(int)$item->document_id && (int)$m->product_variant_id===(int)$item->product_variant_id)) continue;
            $known=$item->new_available!==null && $item->system_available_at_start!==null;
            $delta=$known ? (int)$item->new_available-(int)$item->system_available_at_start : 0;
            $rows->push($this->row('stocktake-'.$item->id,$item->finalized_at,$item->product_variant_id,$item->warehouse_id,$known && $delta!==0 ? ($delta>0?'in':'out') : 'info','انبارگردانی'.($known && $delta===0?'؛ بدون تغییر':''),abs($delta),$item->document_number,route('stock-count-documents.show',$item->document_id),$item->finalized_by,'بر اساس سند نهایی انبارگردانی','سند انبارگردانی',$item->system_available_at_start,$item->new_available));
        }
    }

    private function statusLabel(string $status): string
    {
        return $status===Invoice::STATUS_SHIPPED ? 'ارسال‌شده' : 'ثبت‌شده';
    }
}
