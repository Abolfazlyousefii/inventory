<?php

namespace App\Console\Commands;

use App\Models\Invoice;
use App\Services\CustomerLedgerService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditMissingSalePricesCommand extends Command
{
    protected $signature = 'invoices:audit-zero-price-items {--dry-run : فقط گزارش بگیر و تغییری اعمال نکن} {--fix : فقط مواردی را اصلاح می‌کند که قیمت معتبر از تنوع کالا قابل تشخیص باشد} {--invoice= : شماره فاکتور/پیش‌فاکتور مثل 00246}';

    protected $description = 'Audit zero/null invoice item prices and missing variant sale prices.';

    public function handle(): int
    {
        if ($this->option('fix')) {
            $this->warn('اصلاح خودکار فقط برای ردیف‌هایی انجام می‌شود که product_variants.sell_price معتبر دارند؛ قیمت حدسی ثبت نمی‌شود.');
        }

        $this->info('منبع قیمت فروش عملیاتی: product_variants.sell_price. products.price فقط خلاصه/کمترین قیمت تنوع‌هاست.');

        $this->tableRows('تنوع‌های بدون قیمت فروش', ['variant_id','product_id','product_name','variant_name','sell_price','stock','reserved'],
            DB::table('product_variants as pv')->join('products as p','p.id','=','pv.product_id')
                ->where(fn($q)=>$q->whereNull('pv.sell_price')->orWhere('pv.sell_price','<=',0))
                ->orderBy('pv.product_id')->limit(500)
                ->get(['pv.id as variant_id','pv.product_id','p.name as product_name','pv.variant_name','pv.sell_price','pv.stock','pv.reserved'])->map(fn($r)=>(array)$r)->all()
        );

        $this->tableRows('محصولات بدون قیمت خلاصه', ['product_id','name','price','stock'],
            DB::table('products')->where(fn($q)=>$q->whereNull('price')->orWhere('price','<=',0))
                ->orderBy('id')->limit(500)->get(['id as product_id','name','price','stock'])->map(fn($r)=>(array)$r)->all()
        );

        $this->tableRows('موجودی مثبت با قیمت فروش نامعتبر', ['variant_id','product_id','product_name','variant_name','sell_price','stock','warehouse_stock'],
            DB::table('product_variants as pv')->join('products as p','p.id','=','pv.product_id')
                ->leftJoin('warehouse_stocks as ws','ws.product_variant_id','=','pv.id')
                ->where(fn($q)=>$q->whereNull('pv.sell_price')->orWhere('pv.sell_price','<=',0))
                ->where(fn($q)=>$q->where('pv.stock','>',0)->orWhere('ws.quantity','>',0))
                ->select('pv.id as variant_id','pv.product_id','p.name as product_name','pv.variant_name','pv.sell_price','pv.stock',DB::raw('COALESCE(SUM(ws.quantity),0) as warehouse_stock'))
                ->groupBy('pv.id','pv.product_id','p.name','pv.variant_name','pv.sell_price','pv.stock')
                ->orderByDesc('pv.stock')->limit(500)->get()->map(fn($r)=>(array)$r)->all()
        );

        $this->tableRows('ردیف‌های فاکتور با قیمت صفر/Null', ['invoice_id','invoice_uuid','customer_name','item_id','product_id','variant_id','quantity','price','line_total'],
            DB::table('invoice_items as ii')->join('invoices as i','i.id','=','ii.invoice_id')
                ->where(fn($q)=>$q->whereNull('ii.price')->orWhere('ii.price','<=',0)->orWhereNull('ii.line_total')->orWhere('ii.line_total','<=',0))
                ->orderByDesc('ii.id')->limit(500)
                ->get(['i.id as invoice_id','i.uuid as invoice_uuid','i.customer_name','ii.id as item_id','ii.product_id','ii.variant_id','ii.quantity','ii.price','ii.line_total'])->map(fn($r)=>(array)$r)->all()
        );

        $this->tableRows('موجودی مثبت بدون خرید', ['variant_id','product_id','product_name','variant_name','sell_price','stock','warehouse_stock','purchase_qty','last_movement_at','last_movement_reason'],
            DB::table('product_variants as pv')->join('products as p','p.id','=','pv.product_id')
                ->leftJoin(DB::raw('(select product_variant_id, sum(quantity) purchase_qty from purchase_items group by product_variant_id) pi'), 'pi.product_variant_id','=','pv.id')
                ->leftJoin(DB::raw('(select product_variant_id, sum(quantity) warehouse_stock from warehouse_stocks group by product_variant_id) ws'), 'ws.product_variant_id','=','pv.id')
                ->leftJoin(DB::raw('(select sm1.product_variant_id, sm1.created_at last_movement_at, sm1.reason last_movement_reason from stock_movements sm1 join (select product_variant_id, max(id) id from stock_movements where product_variant_id is not null group by product_variant_id) last on last.id = sm1.id) sm'), 'sm.product_variant_id','=','pv.id')
                ->where(fn($q)=>$q->where('pv.stock','>',0)->orWhere('ws.warehouse_stock','>',0))
                ->whereRaw('COALESCE(pi.purchase_qty,0)=0')
                ->orderByDesc('pv.stock')->limit(500)
                ->get(['pv.id as variant_id','pv.product_id','p.name as product_name','pv.variant_name','pv.sell_price','pv.stock',DB::raw('COALESCE(ws.warehouse_stock,0) as warehouse_stock'),DB::raw('COALESCE(pi.purchase_qty,0) as purchase_qty'),'sm.last_movement_at','sm.last_movement_reason'])->map(fn($r)=>(array)$r)->all()
        );

        if ($uuid = trim((string) $this->option('invoice'))) {
            $this->reportInvoice($uuid);
        }

        if ($this->option('fix')) {
            $this->fixZeroPriceItems();
        }

        return self::SUCCESS;
    }

    private function reportInvoice(string $uuid): void
    {
        $this->info("گزارش فاکتور {$uuid}");
        $this->tableRows('اطلاعات فاکتور', ['invoice_id','uuid','customer_name','customer_mobile','subtotal','discount_amount','shipping_price','total','status'],
            DB::table('invoices')->where('uuid', $uuid)->orWhere('uuid', ltrim($uuid, '0'))->get(['id as invoice_id','uuid','customer_name','customer_mobile','subtotal','discount_amount','shipping_price','total','status'])->map(fn($r)=>(array)$r)->all()
        );
        $this->tableRows('اقلام فاکتور و قیمت تنوع', ['invoice_uuid','item_id','product_name','variant_name','quantity','invoice_price','line_total','variant_sell_price','variant_stock','purchase_qty','last_movement_reason'],
            DB::table('invoice_items as ii')->join('invoices as i','i.id','=','ii.invoice_id')->leftJoin('products as p','p.id','=','ii.product_id')->leftJoin('product_variants as pv','pv.id','=','ii.variant_id')
                ->leftJoin(DB::raw('(select product_variant_id, sum(quantity) purchase_qty from purchase_items group by product_variant_id) pi'), 'pi.product_variant_id','=','pv.id')
                ->leftJoin(DB::raw('(select sm1.product_variant_id, sm1.reason last_movement_reason from stock_movements sm1 join (select product_variant_id, max(id) id from stock_movements where product_variant_id is not null group by product_variant_id) last on last.id = sm1.id) sm'), 'sm.product_variant_id','=','pv.id')
                ->where(fn($q)=>$q->where('i.uuid',$uuid)->orWhere('i.uuid',ltrim($uuid,'0')))
                ->get(['i.uuid as invoice_uuid','ii.id as item_id','p.name as product_name','pv.variant_name','ii.quantity','ii.price as invoice_price','ii.line_total','pv.sell_price as variant_sell_price','pv.stock as variant_stock',DB::raw('COALESCE(pi.purchase_qty,0) as purchase_qty'),'sm.last_movement_reason'])->map(fn($r)=>(array)$r)->all()
        );
    }

    private function fixZeroPriceItems(): void
    {
        $rows = DB::table('invoice_items as ii')
            ->join('product_variants as pv', 'pv.id', '=', 'ii.variant_id')
            ->where(fn ($q) => $q->whereNull('ii.price')->orWhere('ii.price', '<=', 0))
            ->where('pv.sell_price', '>', 0)
            ->get(['ii.id', 'ii.invoice_id', 'ii.quantity', 'ii.line_discount_amount', 'pv.sell_price']);

        if ($this->option('dry-run')) {
            $this->warn("dry-run فعال است؛ {$rows->count()} ردیف قابل اصلاح پیدا شد ولی تغییری اعمال نشد.");
            return;
        }

        $invoiceIds = $rows->pluck('invoice_id')->unique()->values();

        DB::transaction(function () use ($rows, $invoiceIds) {
            foreach ($rows as $row) {
                $price = (int) $row->sell_price;
                $quantity = max((int) $row->quantity, 0);
                $discount = min(max((int) ($row->line_discount_amount ?? 0), 0), $quantity * $price);

                DB::table('invoice_items')->where('id', $row->id)->update([
                    'price' => $price,
                    'line_discount_amount' => $discount,
                    'line_total' => max(($quantity * $price) - $discount, 0),
                    'updated_at' => now(),
                ]);
            }

            Invoice::query()->whereIn('id', $invoiceIds)->lockForUpdate()->with('items')->get()->each(function (Invoice $invoice) {
                $subtotal = (int) $invoice->items->sum(fn ($item) => max((int) $item->quantity, 0) * max((int) $item->price, 0));
                $itemsDiscount = (int) $invoice->items->sum(fn ($item) => min(max((int) ($item->line_discount_amount ?? 0), 0), max((int) $item->quantity, 0) * max((int) $item->price, 0)));
                $discount = min($subtotal, $itemsDiscount + max((int) $invoice->discount_amount, 0));
                $total = max($subtotal - $discount, 0) + max((int) $invoice->shipping_price, 0);

                $invoice->update(['subtotal' => $subtotal, 'total' => $total]);
                app(CustomerLedgerService::class)->syncInvoiceDebit($invoice->fresh());
            });
        });

        $this->info("{$rows->count()} ردیف با قیمت معتبر تنوع کالا اصلاح شد و جمع فاکتور/گردش حساب {$invoiceIds->count()} فاکتور دوباره محاسبه شد.");
    }

    private function tableRows(string $title, array $headers, array $rows): void
    {
        $this->newLine();
        $this->info($title . ' (' . count($rows) . ')');
        if ($rows === []) { $this->line('موردی یافت نشد.'); return; }
        $this->table($headers, $rows);
    }
}
