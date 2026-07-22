<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

class AuditProductPriceIntegrity extends Command
{
    protected $signature = 'inventory:audit-price-integrity {--output=} {--severity=} {--product=} {--variant=} {--format=csv} {--summary}';
    protected $description = 'Read-only audit for zero and inconsistent product/variant prices.';

    private const SEVERITIES = ['Critical' => 4, 'High' => 3, 'Medium' => 2, 'Low' => 1];

    public function handle(): int
    {
        $format = strtolower((string) $this->option('format'));
        if (! in_array($format, ['csv', 'json'], true)) {
            $this->error('--format must be csv or json.');
            return self::FAILURE;
        }

        DB::listen(function ($query): void {
            if (preg_match('/\b(update|delete|truncate|insert|replace|alter|drop)\b/i', $query->sql)) {
                throw new \RuntimeException('Unsafe write query blocked during price audit: '.$query->sql);
            }
        });

        $anomalies = $this->buildAnomalies();
        $anomalies = $this->applyFilters($anomalies);
        $suggestions = $this->buildSuggestions($anomalies);
        $summary = $this->buildSummary($anomalies, $suggestions);

        $paths = $this->writeReports($anomalies, $suggestions, $summary, $format);
        $this->line(json_encode(['summary' => $summary, 'paths' => $paths], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));

        return self::SUCCESS;
    }

    private function buildAnomalies(): array
    {
        $rows = [];
        if (! Schema::hasTable('products')) {
            return $rows;
        }

        $products = DB::table('products as p')
            ->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
            ->when($this->option('product'), fn ($q, $id) => $q->where('p.id', $id))
            ->select('p.*', 'c.name as category_name')
            ->get();

        $variantCounts = $this->groupCount('product_variants', 'product_id');
        $warehouseProduct = $this->warehouseStock(false);
        $warehouseVariant = $this->warehouseStock(true);
        $purchase = $this->purchaseStats();
        $sales = $this->salesStats();
        $pre = $this->preinvoiceStats();
        $priceChanges = $this->priceChangeStats();
        $reserved = $this->reservationStats();
        $minPositive = $this->minLogicalPositivePrice();

        foreach ($products as $p) {
            $productStock = (int) ($warehouseProduct[$p->id] ?? 0);
            $productPrice = $this->num($p->price ?? null);
            $isSellable = (bool) ($p->is_sellable ?? true);
            $hasVariants = (int) ($variantCounts[$p->id] ?? 0) > 0;
            $ctx = $this->baseRow($p, null, $productStock, $reserved['product'][$p->id] ?? 0, $purchase['product'][$p->id] ?? [], $sales['product'][$p->id] ?? [], $pre['product'][$p->id] ?? [], $priceChanges['product'][$p->id] ?? []);

            if (! $hasVariants && $isSellable && $productStock > 0 && $productPrice <= 0) $rows[] = $ctx + ['anomaly_code' => 'A01', 'severity' => 'Critical', 'probable_root_cause' => 'Product current price is zero while warehouse stock is positive and no valid variant price can override it.'];
            if (! $hasVariants && ! $isSellable && $productPrice <= 0) $rows[] = $ctx + ['anomaly_code' => 'A07', 'severity' => 'Low', 'probable_root_cause' => 'Non-sellable product has no current price.'];
            if ($productPrice < 0) $rows[] = $ctx + ['anomaly_code' => 'A15', 'severity' => 'Critical', 'probable_root_cause' => 'Negative product price.'];
            if ($productPrice > 0 && $productPrice < $minPositive) $rows[] = $ctx + ['anomaly_code' => 'A16', 'severity' => 'Medium', 'probable_root_cause' => 'Product price is below observed logical distribution floor.'];
        }

        if (Schema::hasTable('product_variants')) {
            $variants = DB::table('product_variants as v')->join('products as p', 'p.id', '=', 'v.product_id')->leftJoin('categories as c', 'c.id', '=', 'p.category_id')
                ->when($this->option('product'), fn ($q, $id) => $q->where('p.id', $id))->when($this->option('variant'), fn ($q, $id) => $q->where('v.id', $id))
                ->select('v.*', 'p.name as product_name', 'p.code as product_code', 'p.sku as product_sku', 'p.price as product_price', 'p.stock as product_stock', 'p.reserved as product_reserved', 'p.is_sellable', 'p.updated_at as product_updated_at', 'c.name as category_name')->get();
            $positiveByProduct = [];
            foreach ($variants as $v) if ($this->num($v->sell_price) > 0 && (bool)($v->is_active ?? true) && (bool)($v->sales_enabled ?? true)) $positiveByProduct[$v->product_id][] = $this->num($v->sell_price);
            foreach ($variants as $v) {
                $stock = (int) ($warehouseVariant[$v->id] ?? 0); $sell = $this->num($v->sell_price); $buy = $this->num($v->buy_price); $active = (bool)($v->is_active ?? true); $salesEnabled = (bool)($v->sales_enabled ?? true);
                $ctx = $this->baseRow($v, $v, $stock, $reserved['variant'][$v->id] ?? 0, $purchase['variant'][$v->id] ?? [], $sales['variant'][$v->id] ?? [], $pre['variant'][$v->id] ?? [], $priceChanges['variant'][$v->id] ?? []);
                if ($active && $salesEnabled && $stock > 0 && $sell <= 0) $rows[] = $ctx + ['anomaly_code' => 'A02', 'severity' => 'Critical', 'probable_root_cause' => 'Active sellable variant has positive warehouse stock but zero sell_price.'];
                if ($this->num($v->product_price) > 0 && $stock > 0 && $sell <= 0) $rows[] = $ctx + ['anomaly_code' => 'A03', 'severity' => 'Critical', 'probable_root_cause' => 'Product summary price is positive but stocked variant price is zero.'];
                if ($this->num($v->product_price) <= 0 && ! empty($positiveByProduct[$v->product_id])) $rows[] = $ctx + ['anomaly_code' => 'A04', 'severity' => 'Medium', 'probable_root_cause' => 'Product summary price is zero while active variants have positive prices.'];
                if ($this->num($v->product_price) > 0 && $sell > 0 && abs($this->num($v->product_price) - $sell) > 0) $rows[] = $ctx + ['anomaly_code' => 'A05', 'severity' => 'Medium', 'probable_root_cause' => 'Product summary price differs from variant sell_price; preinvoice must use variant price.'];
                if ($buy <= 0 && ($stock > 0 || (int)($ctx['purchase_quantity'] ?? 0) > 0)) $rows[] = $ctx + ['anomaly_code' => 'A06', 'severity' => 'High', 'probable_root_cause' => 'buy_price is zero despite stock or purchase history.'];
                if (! $active && $stock <= 0 && $sell <= 0) $rows[] = $ctx + ['anomaly_code' => 'A07', 'severity' => 'Low', 'probable_root_cause' => 'Inactive/unstocked variant with zero price.'];
                if ((int)($ctx['purchase_quantity'] ?? 0) > 0 && $stock > 0 && $sell <= 0) $rows[] = $ctx + ['anomaly_code' => 'A09', 'severity' => 'Critical', 'probable_root_cause' => 'Variant has purchases and stock but current sell_price is zero.'];
                if (($ctx['last_positive_sale_price'] ?? 0) > 0 && $sell <= 0) $rows[] = $ctx + ['anomaly_code' => 'A10', 'severity' => 'Critical', 'probable_root_cause' => 'Historical positive sale price exists but current sell_price is zero.'];
                if ($sell < 0 || $buy < 0) $rows[] = $ctx + ['anomaly_code' => 'A15', 'severity' => 'Critical', 'probable_root_cause' => 'Negative variant price.'];
                if ($sell > 0 && $sell < $minPositive) $rows[] = $ctx + ['anomaly_code' => 'A16', 'severity' => 'Medium', 'probable_root_cause' => 'Variant price is below observed logical distribution floor.'];
            }
        }
        return array_merge($rows, $this->documentAnomalies('invoice'), $this->documentAnomalies('preinvoice'));
    }

    private function baseRow(object $p, ?object $v, int $warehouseStock, int $reservedStock, array $purchase, array $sales, array $pre, array $change): array
    {
        return ['product_id'=>(int)($p->product_id ?? $p->id),'product_name'=>(string)($p->product_name ?? $p->name ?? ''),'product_code'=>(string)($p->product_code ?? $p->code ?? $p->sku ?? ''),'category'=>(string)($p->category_name ?? ''),'variant_id'=>$v ? (int)$v->id : null,'variant_name'=>$v->variant_name ?? null,'variant_code'=>$v->variant_code ?? null,'is_sellable'=>(bool)($p->is_sellable ?? true),'is_active'=>$v ? (bool)($v->is_active ?? true) : null,'sales_enabled'=>$v ? (bool)($v->sales_enabled ?? true) : null,'product_price'=>$this->num($p->product_price ?? $p->price ?? null),'variant_sell_price'=>$v ? $this->num($v->sell_price ?? null) : null,'variant_buy_price'=>$v ? $this->num($v->buy_price ?? null) : null,'cached_product_stock'=>(int)($p->product_stock ?? $p->stock ?? 0),'cached_variant_stock'=>$v ? (int)($v->stock ?? 0) : null,'warehouse_stock'=>$warehouseStock,'reserved_stock'=>$reservedStock,'purchase_quantity'=>(int)($purchase['qty'] ?? 0),'sales_quantity'=>(int)($sales['qty'] ?? 0),'last_purchase_price'=>$purchase['last_price'] ?? null,'last_positive_sale_price'=>$sales['last_price'] ?? null,'last_positive_sale_at'=>$sales['last_at'] ?? null,'last_positive_preinvoice_price'=>$pre['last_price'] ?? null,'last_price_change'=>$change['last_price'] ?? null,'last_price_change_at'=>$change['last_at'] ?? null,'last_sync_at'=>$p->synced_at ?? $v->synced_at ?? null,'updated_at'=>$p->product_updated_at ?? $p->updated_at ?? null];
    }

    private function documentAnomalies(string $type): array
    {
        $isInvoice = $type === 'invoice'; $table = $isInvoice ? 'invoice_items' : 'preinvoice_order_items'; $doc = $isInvoice ? 'invoices' : 'preinvoice_orders';
        if (! Schema::hasTable($table)) return [];
        $fk = $isInvoice ? 'invoice_id' : 'preinvoice_order_id'; $rows = [];
        foreach (DB::table($table.' as i')->leftJoin($doc.' as d', 'd.id', '=', 'i.'.$fk)->where('i.price', '<=', 0)->where('i.quantity', '>', 0)->select('i.*', 'd.status', 'd.total')->limit(10000)->get() as $i) {
            $rows[] = ['product_id'=>(int)$i->product_id,'product_name'=>'','product_code'=>'','category'=>'','variant_id'=>$i->variant_id ? (int)$i->variant_id : null,'variant_name'=>null,'variant_code'=>null,'is_sellable'=>null,'is_active'=>null,'sales_enabled'=>null,'product_price'=>null,'variant_sell_price'=>null,'variant_buy_price'=>null,'cached_product_stock'=>null,'cached_variant_stock'=>null,'warehouse_stock'=>0,'reserved_stock'=>0,'purchase_quantity'=>0,'sales_quantity'=>0,'last_purchase_price'=>null,'last_positive_sale_price'=>null,'last_positive_sale_at'=>null,'last_positive_preinvoice_price'=>null,'last_price_change'=>null,'last_price_change_at'=>null,'last_sync_at'=>null,'updated_at'=>$i->updated_at ?? null,'anomaly_code'=>$isInvoice?'A12':'A11','severity'=>'Critical','probable_root_cause'=>($isInvoice?'Invoice':'Preinvoice').' line has zero/negative price with positive quantity.'];
        }
        return $rows;
    }

    private function buildSuggestions(array $anomalies): array { return array_map(function ($r) { $price = $r['last_positive_sale_price'] ?: $r['last_price_change'] ?: $r['last_positive_preinvoice_price'] ?: null; return $r + ['suggested_price'=>$price,'suggestion_source'=>$r['last_positive_sale_price']?'invoice':($r['last_price_change']?'price_change':($r['last_positive_preinvoice_price']?'preinvoice':'none')),'source_record_id'=>null,'source_date'=>$r['last_positive_sale_at'] ?: $r['last_price_change_at'],'confidence'=>$r['last_positive_sale_price']||$r['last_price_change']?'High':($r['last_positive_preinvoice_price']?'Low':'None'),'requires_manual_review'=>$price ? false : true]; }, $anomalies); }
    private function buildSummary(array $a, array $s): array { return ['product_zero_prices'=>$this->countProductsZero(),'variant_zero_sell_prices'=>$this->countVariantsZero(),'positive_warehouse_stock'=>count(array_filter($a, fn($r)=>($r['warehouse_stock']??0)>0)),'sellable_zero_price'=>count(array_filter($a, fn($r)=>($r['is_sellable']??false)&&in_array($r['anomaly_code'],['A01','A02','A03','A09','A10'],true))),'purchased_positive_current_zero'=>count(array_filter($a, fn($r)=>$r['anomaly_code']==='A09')),'sold_positive_current_zero'=>count(array_filter($a, fn($r)=>$r['anomaly_code']==='A10')),'invoice_zero_line_items'=>count(array_filter($a, fn($r)=>$r['anomaly_code']==='A12')),'preinvoice_zero_line_items'=>count(array_filter($a, fn($r)=>$r['anomaly_code']==='A11')),'product_summary_desync'=>count(array_filter($a, fn($r)=>in_array($r['anomaly_code'],['A04','A05'],true))),'zero_buy_prices'=>$this->countBuyZero(),'high_confidence_suggestions'=>count(array_filter($s, fn($r)=>$r['confidence']==='High')),'manual_pricing_required'=>count(array_filter($s, fn($r)=>$r['confidence']==='None')),'total_anomalies'=>count($a),'by_severity'=>array_count_values(array_column($a,'severity')),'by_code'=>array_count_values(array_column($a,'anomaly_code')),'data_changed'=>false]; }
    private function writeReports(array $a, array $s, array $summary, string $format): array { $dir = $this->option('output') ?: 'reports/price-integrity'; $stamp = now()->format('Ymd-Hi'); $paths=[]; Storage::disk('local')->makeDirectory($dir); $paths['summary']="$dir/price-integrity-summary-$stamp.json"; Storage::disk('local')->put($paths['summary'], json_encode($summary, JSON_UNESCAPED_UNICODE|JSON_PRETTY_PRINT)); foreach ([['anomalies',$a],['suggestions',$s]] as [$name,$rows]) { $paths[$name]="$dir/price-integrity-$name-$stamp.csv"; $fh=fopen('php://temp','r+'); $head=['anomaly_code','severity','product_id','product_name','variant_id','variant_name','warehouse_stock','purchase_quantity','sales_quantity','product_price','variant_sell_price','variant_buy_price','last_positive_sale_price','last_positive_sale_at','suggested_price','suggestion_source','confidence','probable_root_cause','requires_manual_review']; fputcsv($fh,$head); foreach($rows as $r) fputcsv($fh,array_map(fn($h)=>$r[$h]??'', $head)); rewind($fh); Storage::disk('local')->put($paths[$name], stream_get_contents($fh)); } return array_map(fn($p)=>Storage::disk('local')->path($p),$paths); }
    private function applyFilters(array $rows): array { $sev=$this->option('severity'); return array_values(array_filter($rows, fn($r)=>!$sev || (self::SEVERITIES[$r['severity']]??0) >= (self::SEVERITIES[ucfirst(strtolower($sev))]??0))); }
    private function groupCount($t,$col): array { return Schema::hasTable($t)?DB::table($t)->select($col,DB::raw('count(*) c'))->groupBy($col)->pluck('c',$col)->map(fn($v)=>(int)$v)->all():[]; }
    private function warehouseStock(bool $variant): array { if(!Schema::hasTable('warehouse_stocks')) return []; $q=DB::table('warehouse_stocks')->select($variant?'product_variant_id':'product_id',DB::raw('sum(quantity) q'))->where($variant?'product_variant_id':'product_variant_id',$variant?'<>':'=', $variant?null:null); if($variant) $q=DB::table('warehouse_stocks')->whereNotNull('product_variant_id')->select('product_variant_id',DB::raw('sum(quantity) q'))->groupBy('product_variant_id'); else $q=DB::table('warehouse_stocks')->whereNull('product_variant_id')->select('product_id',DB::raw('sum(quantity) q'))->groupBy('product_id'); return $q->pluck('q',$variant?'product_variant_id':'product_id')->map(fn($v)=>(int)$v)->all(); }
    private function reservationStats(): array { return ['product'=>Schema::hasTable('products')?DB::table('products')->pluck('reserved','id')->map(fn($v)=>(int)$v)->all():[], 'variant'=>Schema::hasTable('product_variants')?DB::table('product_variants')->pluck('reserved','id')->map(fn($v)=>(int)$v)->all():[]]; }
    private function purchaseStats(): array { return $this->stats('purchase_items','product_variant_id','product_id','quantity','buy_price','created_at'); }
    private function salesStats(): array { return $this->stats('invoice_items','variant_id','product_id','quantity','price','created_at', true); }
    private function preinvoiceStats(): array { return $this->stats('preinvoice_order_items','variant_id','product_id','quantity','price','created_at', true); }
    private function priceChangeStats(): array { return Schema::hasTable('price_change_document_items') ? $this->stats('price_change_document_items','product_variant_id','product_id',null,'new_price','applied_at',true) : ['product'=>[],'variant'=>[]]; }
    private function stats($table,$vcol,$pcol,$qcol,$price,$date,$positive=false): array { $out=['product'=>[],'variant'=>[]]; if(!Schema::hasTable($table)) return $out; $rows=DB::table($table)->when($positive,fn($q)=>$q->where($price,'>',0))->orderBy($date,'desc')->get([$pcol,$vcol,$qcol ? $qcol : DB::raw('0 as quantity'),$price,$date]); foreach($rows as $r){ foreach([['product',$r->$pcol],['variant',$r->$vcol]] as [$k,$id]) if($id){ $out[$k][$id]['qty']=($out[$k][$id]['qty']??0)+(int)($r->quantity??0); $out[$k][$id]['last_price']=$out[$k][$id]['last_price']??$this->num($r->$price); $out[$k][$id]['last_at']=$out[$k][$id]['last_at']??($r->$date??null); } } return $out; }
    private function minLogicalPositivePrice(): int { $prices=[]; if(Schema::hasTable('products')) $prices=array_merge($prices, DB::table('products')->where('price','>',0)->orderBy('price')->limit(100)->pluck('price')->all()); if(Schema::hasTable('product_variants')) $prices=array_merge($prices, DB::table('product_variants')->where('sell_price','>',0)->orderBy('sell_price')->limit(100)->pluck('sell_price')->all()); sort($prices); return (int)($prices[0] ?? 1); }
    private function countProductsZero(): int { return Schema::hasTable('products') ? DB::table('products')->where(fn($q)=>$q->whereNull('price')->orWhere('price','<=',0))->count() : 0; }
    private function countVariantsZero(): int { return Schema::hasTable('product_variants') ? DB::table('product_variants')->where(fn($q)=>$q->whereNull('sell_price')->orWhere('sell_price','<=',0))->count() : 0; }
    private function countBuyZero(): int { return Schema::hasTable('product_variants') ? DB::table('product_variants')->where(fn($q)=>$q->whereNull('buy_price')->orWhere('buy_price','<=',0))->count() : 0; }
    private function num($v): int { return is_null($v) ? 0 : (int) $v; }
}
