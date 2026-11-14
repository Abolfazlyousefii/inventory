<?php

namespace App\Services;

use App\Models\{PurchaseReturn, SalesReturnCorrection, SalesReturnDocument, SalesReturnDocumentItem, WarehouseInboundReceiptItem};
use Illuminate\Support\Facades\{DB, Schema};

/** Database evidence only. This service cannot certify physical possession or repair stock. */
final class ReturnsIntegrityAuditService
{
    public function report(string $salesNumber = '432', string $purchaseNumber = '00004'): array
    {
        if (DB::transactionLevel() === 0 && DB::getDriverName() === 'mysql') {
            DB::statement('SET TRANSACTION READ ONLY');
        }
        return DB::transaction(function () use ($salesNumber, $purchaseNumber): array {
            $sales = DB::table('sales_return_documents')->where('document_number', $salesNumber)->first();
            $purchase = DB::table('purchase_returns')->where('document_number', $purchaseNumber)->first();
            $salesItems = $sales ? DB::table('sales_return_document_items')->where('document_id', $sales->id)->get() : collect();
            $receipts = $sales ? DB::table('warehouse_inbound_receipts')->where('source_type', 'sales_return')->where('source_id', $sales->id)->get() : collect();
            $receiptItems = DB::table('warehouse_inbound_receipt_items')->whereIn('receipt_id', $receipts->pluck('id'))->get();
            $corrections = $sales && Schema::hasTable('sales_return_corrections')
                ? DB::table('sales_return_corrections')->where('document_id', $sales->id)->pluck('id') : collect();
            $salesMovements = DB::table('stock_movements')->where(function ($q) use ($sales, $salesItems, $receiptItems, $corrections) {
                $q->whereRaw('1 = 0');
                if ($sales) $q->orWhere(fn ($r) => $r->where('reference_type', SalesReturnDocument::class)->where('reference_id', $sales->id));
                $q->orWhere(fn ($r) => $r->where('reference_type', SalesReturnDocumentItem::class)->whereIn('reference_id', $salesItems->pluck('id')))
                    ->orWhere(fn ($r) => $r->where('reference_type', WarehouseInboundReceiptItem::class)->whereIn('reference_id', $receiptItems->pluck('id')))
                    ->orWhere(fn ($r) => $r->where('reference_type', SalesReturnCorrection::class)->whereIn('reference_id', $corrections));
            })->get();
            $purchaseItems = $purchase ? DB::table('purchase_return_items')->where('purchase_return_id', $purchase->id)->get() : collect();
            $variants = $salesItems->pluck('product_variant_id')->merge($purchaseItems->pluck('product_variant_id'))->filter()->unique();
            $stocks = DB::table('warehouse_stocks')->whereIn('product_variant_id', $variants)->get(['id', 'warehouse_id', 'product_id', 'product_variant_id', 'quantity', 'updated_at']);
            $movements = DB::table('stock_movements')->whereIn('product_variant_id', $variants)->orderBy('id')->get();
            $ledgers = $sales ? DB::table('customer_ledgers')->where('customer_id', $sales->customer_id)->where(function ($q) use ($sales, $receipts, $corrections) {
                $q->where(fn ($r) => $r->where('reference_type', SalesReturnDocument::class)->where('reference_id', $sales->id))
                    ->orWhere(fn ($r) => $r->where('reference_type', WarehouseInboundService::TRANSACTION_TYPE)->whereIn('reference_id', $receipts->pluck('id')))
                    ->orWhere(fn ($r) => $r->where('reference_type', SalesReturnCorrection::class)->whereIn('reference_id', $corrections));
            })->get(['id', 'type', 'amount', 'reference_type', 'reference_id', 'created_at']) : collect();
            $purchaseId = $purchase?->purchase_id;
            $counts = [];
            foreach (['sales_return_documents', 'purchase_returns', 'stock_movements', 'warehouse_stocks', 'customer_ledgers', 'supplier_ledgers'] as $table) {
                $counts[$table] = DB::table($table)->count();
            }
            return [
                'data_changed' => false, 'record_counts' => $counts,
                'sales_return' => ['document' => $sales ? (array) $sales : null, 'items' => $salesItems->all(), 'receipts' => $receipts->all(),
                    'receipt_items' => $receiptItems->all(), 'stock_movements' => $salesMovements->all(), 'customer_ledger' => $ledgers->all()],
                'purchase_return' => ['document' => $purchase ? (array) $purchase : null, 'items' => $purchaseItems->all(),
                    'source_purchase' => $purchaseId ? DB::table('purchases')->where('id', $purchaseId)->first() : null,
                    'source_items' => DB::table('purchase_items')->whereIn('id', $purchaseItems->pluck('purchase_item_id'))->get()->all(),
                    'active_returns_for_purchase' => DB::table('purchase_returns')->where('purchase_id', $purchaseId)->whereNull('deleted_at')->get()->all(),
                    'supplier_ledger' => $purchase ? DB::table('supplier_ledgers')->where('reference_type', PurchaseReturn::class)->where('reference_id', $purchase->id)->get()->all() : [],
                    'stock_movements' => $purchase ? DB::table('stock_movements')->where('reference', 'PR-'.$purchase->document_number)->get()->all() : []],
                'warehouse_stocks' => $stocks->all(), 'warehouses' => DB::table('warehouses')->whereIn('id', $stocks->pluck('warehouse_id')->merge($salesItems->pluck('destination_warehouse_id'))->unique())->get(['id', 'name', 'type', 'is_active'])->all(),
                'variant_movement_history' => $movements->all(),
                'repair_preview' => ['status' => 'needs_physical_confirmation', 'apply_supported' => false,
                    'financial_credit_to_post' => 0, 'eligible_without_approval' => false,
                    'candidate_items_without_recorded_inbound' => $salesItems->filter(fn ($i) => ! $receiptItems->contains('source_item_id', $i->id)
                        && ! $salesMovements->contains(fn ($m) => (int) $m->product_variant_id === (int) $i->product_variant_id))
                        ->map(fn ($i) => ['source_item_id' => $i->id, 'product_id' => $i->product_id, 'variant_id' => $i->product_variant_id,
                            'destination_warehouse_id' => $i->destination_warehouse_id, 'quantity_needing_confirmation' => (int) $i->return_quantity])->values()->all()],
            ];
        });
    }
}
