<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class AuditMissingSalePricesCommand extends Command
{
    protected $signature = 'products:audit-missing-sale-prices {--dry-run : فقط گزارش بگیر و تغییری اعمال نکن} {--fix : فعلاً فقط گزارش می‌دهد؛ قیمت فروش حدسی اصلاح نمی‌شود} {--invoice= : شماره فاکتور/پیش‌فاکتور مثل 00246}';

    protected $description = 'Audit variants/products with missing sale prices, zero-price invoice rows, and positive stock without purchases.';

    public function handle(): int
    {
        if ($this->option('fix')) {
            $this->warn('اصلاح خودکار قیمت فروش انجام نمی‌شود؛ قیمت واقعی نباید حدسی ثبت شود. این اجرا فقط گزارش است.');
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

    private function tableRows(string $title, array $headers, array $rows): void
    {
        $this->newLine();
        $this->info($title . ' (' . count($rows) . ')');
        if ($rows === []) { $this->line('موردی یافت نشد.'); return; }
        $this->table($headers, $rows);
    }
}
