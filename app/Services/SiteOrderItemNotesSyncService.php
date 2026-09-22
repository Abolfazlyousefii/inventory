<?php

namespace App\Services;

use App\Models\Invoice;
use App\Models\ProductVariant;
use App\Models\Site\Order as SiteOrder;
use App\Models\Site\OrderItem as SiteOrderItem;
use App\Models\Site\Price as SitePrice;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SiteOrderItemNotesSyncService {
    /**
     * Ledger events can be emitted before the InvoiceItem event in the same
     * request. Keep them temporarily and attach them as soon as the affected
     * site item is known. The key contains the request correlation id, so data
     * from separate requests never mixes.
     *
     * @var array<string, array<int, array{action:string,payload:array,actor_user_id:?int,request:array}>>
     */
    private static array $pendingLedgerEvents = [];

    public function sync( string $action, array $payload, string $correlationId, ?int $actorUserId, array $requestContext ): array {
        $invoiceId = $this->resolveInvoiceId($payload);
        if ( $invoiceId <= 0 ) {
            return [ 'status' => 'ignored', 'reason' => 'invoice_id_not_resolved' ];
        }

        /** @var Invoice|null $invoice */
        $invoice = Invoice::query()
            ->with([ 'payments' ])
            ->find($invoiceId);
        if ( !$invoice ) {
            return [ 'status' => 'ignored', 'reason' => 'invoice_not_found', 'invoice_id' => $invoiceId ];
        }

        $externalOrderId = (int) ( $invoice->external_order_id ?? 0 );
        if ( $externalOrderId <= 0 ) {
            return [
                'status'     => 'ignored',
                'reason'     => 'invoice_has_no_external_order_id',
                'invoice_id' => $invoiceId,
            ];
        }

        /** @var SiteOrder|null $siteOrder */
        $siteOrder = SiteOrder::query()
            ->find($externalOrderId);
        if ( !$siteOrder ) {
            Log::warning('[site-order-notes-sync] site order not found', [
                'invoice_id'           => $invoiceId,
                'external_order_id'    => $externalOrderId,
                'action'               => $action,
                'audit_correlation_id' => $correlationId,
            ]);

            return [
                'status'            => 'ignored',
                'reason'            => 'site_order_not_found',
                'invoice_id'        => $invoiceId,
                'external_order_id' => $externalOrderId,
            ];
        }

        if ( in_array($action, [ 'invoice_item_added', 'invoice_item_updated', 'invoice_item_removed' ], true) ) {
            return $this->syncItemChange($siteOrder, $invoice, $action, $payload, $correlationId, $actorUserId, $requestContext);
        }

        if ( $action === 'invoice_financial_or_status_updated' ) {
            return $this->syncInvoiceAdjustment($siteOrder, $invoice, $action, $payload, $correlationId, $actorUserId, $requestContext);
        }

        if ( str_starts_with($action, 'customer_ledger_') || $action === 'customer_invoice_debit_voided' ) {
            return $this->syncLedgerEvent($siteOrder, $invoice, $action, $payload, $correlationId, $actorUserId, $requestContext);
        }

        return [
            'status' => 'ignored',
            'reason' => 'action_not_supported_for_site_notes',
            'action' => $action,
        ];
    }

    private function syncItemChange( SiteOrder $siteOrder, Invoice $invoice, string $action, array $payload, string $correlationId, ?int $actorUserId, array $requestContext ): array {
        $before           = is_array($payload['before'] ?? null) ? $payload['before'] : [];
        $after            = is_array($payload['after'] ?? null) ? $payload['after'] : [];
        $erpInvoiceItemId = (int) ( $payload['invoice_item_id'] ?? $after['id'] ?? $before['id'] ?? 0 );

        $variantId    = (int) ( $after['variant_id'] ?? $before['variant_id'] ?? 0 );
        $erpProductId = (int) ( $after['product_id'] ?? $before['product_id'] ?? 0 );

        $variant = $variantId > 0 ? ProductVariant::query()
            ->with('product')
            ->find($variantId) : null;

        $sitePriceId   = $variant?->variety_id !== null ? (int) $variant->variety_id : 0;
        $siteProductId = $this->positiveInteger($variant?->product?->external_id);

        /** @var SitePrice|null $sitePrice */
        $sitePrice = $sitePriceId > 0 ? SitePrice::query()
            ->find($sitePriceId) : null;

        if ( !$sitePrice && $variant && trim((string) $variant->variant_code) !== '' ) {
            $sitePrice = SitePrice::query()
                ->where('variant_code', trim((string) $variant->variant_code))
                ->first();

            if ( $sitePrice ) {
                $sitePriceId = (int) $sitePrice->id;
            }
        }

        if ( $siteProductId <= 0 && $sitePrice ) {
            $siteProductId = (int) $sitePrice->product_id;
        }

        return DB::connection('site')
            ->transaction(function () use (
                $siteOrder, $invoice, $action, $payload, $before, $after, $erpInvoiceItemId, $erpProductId, $variant, $sitePriceId, $siteProductId, $sitePrice, $correlationId, $actorUserId, $requestContext,
            ): array {
                $siteItems = $siteOrder->items()
                    ->lockForUpdate()
                    ->get();
                $siteItem  = $this->findMatchingSiteOrderItem($siteItems, $erpInvoiceItemId, $sitePriceId, $siteProductId, (int) ( $before['quantity'] ?? 0 ));

                $createdSiteItem = false;
                if ( !$siteItem && $action === 'invoice_item_added' ) {
                    $siteItem        = $this->createSiteOrderItemForAddedInvoiceItem($siteOrder, $invoice, $after, $variant, $sitePrice, $sitePriceId, $siteProductId);
                    $createdSiteItem = $siteItem !== null;
                }

                if ( !$siteItem ) {
                    Log::warning('[site-order-notes-sync] matching site order item not found', [
                        'invoice_id'           => (int) $invoice->id,
                        'external_order_id'    => (int) $siteOrder->id,
                        'invoice_item_id'      => $erpInvoiceItemId,
                        'erp_product_id'       => $erpProductId,
                        'erp_variant_id'       => (int) ( $variant?->id ?? 0 ),
                        'site_product_id'      => $siteProductId,
                        'site_price_id'        => $sitePriceId,
                        'action'               => $action,
                        'audit_correlation_id' => $correlationId,
                    ]);

                    return [
                        'status'            => 'ignored',
                        'reason'            => 'site_order_item_not_found',
                        'external_order_id' => (int) $siteOrder->id,
                        'invoice_item_id'   => $erpInvoiceItemId,
                        'site_price_id'     => $sitePriceId,
                    ];
                }

                $existingNotes       = $siteItem->notesArray();
                $previousFulfillment = is_array($existingNotes['fulfillment'] ?? null) ? $existingNotes['fulfillment'] : [];

                $siteOriginalQuantity = max((int) $siteItem->quantity, 0);
                $paidQuantity         = array_key_exists('paid_quantity', $previousFulfillment) ? max((int) $previousFulfillment['paid_quantity'], 0) : ( $createdSiteItem ? 0 : $siteOriginalQuantity );

                $newQuantity      = max((int) ( $after['quantity'] ?? 0 ), 0);
                $oldQuantity      = max((int) ( $before['quantity'] ?? 0 ), 0);
                $approvedQuantity = $newQuantity;
                $unsentQuantity   = max($paidQuantity - $approvedQuantity, 0);
                $addedQuantity    = max($approvedQuantity - $paidQuantity, 0);

                $changeType = $this->changeType($action, $oldQuantity, $newQuantity, $before, $after);
                $status     = $newQuantity <= 0 ? 'removed' : ( ( $unsentQuantity > 0 || $addedQuantity > 0 || $changeType !== 'unchanged' ) ? 'adjusted' : 'approved' );

                $paidUnitPrice  = max($siteItem->finalUnitPrice(), 0);
                $paidAmount     = $paidUnitPrice * $paidQuantity;
                $approvedAmount = $after !== [] ? max((int) ( $after['line_total'] ?? ( $newQuantity * (int) ( $after['price'] ?? 0 ) ) ), 0) : 0;
                $unsentAmount   = $unsentQuantity > 0 ? $paidUnitPrice * $unsentQuantity : 0;
                $addedAmount    = max($approvedAmount - max($paidAmount - $unsentAmount, 0), 0);

                $reason = trim((string) ( $requestContext['change_reason'] ?? '' ));
                $note   = trim((string) ( $requestContext['change_note'] ?? '' ));

                $fulfillment = [
                    'status'                => $status,
                    'change_type'           => $changeType,
                    'customer_message'      => $this->customerMessage($changeType, $paidQuantity, $approvedQuantity, $unsentQuantity, $addedQuantity, $reason, $note),
                    'change_reason'         => $reason !== '' ? $reason : null,
                    'change_note'           => $note !== '' ? $note : null,
                    'paid_quantity'         => $paidQuantity,
                    'approved_quantity'     => $approvedQuantity,
                    'unsent_quantity'       => $unsentQuantity,
                    'added_quantity'        => $addedQuantity,
                    'returned_quantity'     => max((int) ( $previousFulfillment['returned_quantity'] ?? 0 ), 0),
                    'paid_amount'           => $paidAmount,
                    'approved_amount'       => $approvedAmount,
                    'unsent_amount'         => $unsentAmount,
                    'added_amount'          => $addedAmount,
                    'returned_amount'       => max((int) ( $previousFulfillment['returned_amount'] ?? 0 ), 0),
                    'old_quantity'          => $oldQuantity,
                    'new_quantity'          => $newQuantity,
                    'old_price'             => $before['price'] ?? null,
                    'new_price'             => $after['price'] ?? null,
                    'old_discount_amount'   => $before['line_discount_amount'] ?? null,
                    'new_discount_amount'   => $after['line_discount_amount'] ?? null,
                    'old_line_total'        => $before['line_total'] ?? 0,
                    'new_line_total'        => $after['line_total'] ?? 0,
                    'line_total_difference' => (int) ( $after['line_total'] ?? 0 ) - (int) ( $before['line_total'] ?? 0 ),
                ];

                $sync = $this->syncMetadata($invoice, $siteOrder, $siteItem, $erpInvoiceItemId, $sitePriceId, $siteProductId, $correlationId, $action);

                $eventKey = $this->eventKey($correlationId, $action, $payload, (int) $siteItem->id);
                $history  = $this->historyEntry($eventKey, $correlationId, $action, $actorUserId, $requestContext, $payload);

                $siteItem->mergeErpNotes([
                    'fulfillment' => $fulfillment,
                    'sync'        => $sync,
                ], $history);

                // A ledger event may have arrived before this item event.
                $this->applyPendingLedgerEvents($invoice, collect([ $siteItem->fresh() ]), $correlationId);

                Log::info('[site-order-notes-sync] order item notes synced', [
                    'invoice_id'           => (int) $invoice->id,
                    'external_order_id'    => (int) $siteOrder->id,
                    'invoice_item_id'      => $erpInvoiceItemId,
                    'site_order_item_id'   => (int) $siteItem->id,
                    'site_price_id'        => $sitePriceId,
                    'action'               => $action,
                    'change_type'          => $changeType,
                    'audit_correlation_id' => $correlationId,
                ]);

                return [
                    'status'                  => 'synced',
                    'external_order_id'       => (int) $siteOrder->id,
                    'site_order_item_ids'     => [ (int) $siteItem->id ],
                    'created_site_order_item' => $createdSiteItem,
                ];
            });
    }

    private function syncInvoiceAdjustment( SiteOrder $siteOrder, Invoice $invoice, string $action, array $payload, string $correlationId, ?int $actorUserId, array $requestContext ): array {
        return DB::connection('site')
            ->transaction(function () use (
                $siteOrder, $invoice, $action, $payload, $correlationId, $actorUserId, $requestContext,
            ): array {
                $siteItems = $siteOrder->items()
                    ->lockForUpdate()
                    ->get();
                $affected  = $this->affectedItemsForCorrelation($siteItems, (int) $invoice->id, $correlationId);

                $adjustment = data_get($payload, 'notes_candidate.invoice_adjustment', []);
                $newStatus  = (string) ( $adjustment['new_status'] ?? $invoice->status ?? '' );

                // Cancellation may have no InvoiceItem event. In that case the
                // whole external order is affected and every row must show it.
                if ( $affected->isEmpty() && $this->isWholeInvoiceStatus($newStatus) ) {
                    $affected = $siteItems;
                }

                if ( $affected->isEmpty() ) {
                    return [
                        'status'            => 'deferred_or_ignored',
                        'reason'            => 'no_changed_site_items_for_invoice_adjustment',
                        'external_order_id' => (int) $siteOrder->id,
                    ];
                }

                foreach ( $affected as $siteItem ) {
                    $eventKey = $this->eventKey($correlationId, $action, $payload, (int) $siteItem->id);
                    $siteItem->mergeErpNotes([
                        'invoice_adjustment' => is_array($adjustment) ? $adjustment : [],
                        'sync'               => [
                            'correlation_id' => $correlationId,
                            'last_action'    => $action,
                            'last_synced_at' => now()->toISOString(),
                        ],
                    ], $this->historyEntry($eventKey, $correlationId, $action, $actorUserId, $requestContext, $payload));
                }

                $this->applyPendingLedgerEvents($invoice, $affected, $correlationId);
                unset(self::$pendingLedgerEvents[$this->pendingKey($correlationId, (int) $invoice->id)]);

                return [
                    'status'              => 'synced',
                    'external_order_id'   => (int) $siteOrder->id,
                    'site_order_item_ids' => $affected->pluck('id')
                        ->map(fn( $id ) => (int) $id)
                        ->values()
                        ->all(),
                ];
            });
    }

    private function syncLedgerEvent( SiteOrder $siteOrder, Invoice $invoice, string $action, array $payload, string $correlationId, ?int $actorUserId, array $requestContext ): array {
        return DB::connection('site')
            ->transaction(function () use (
                $siteOrder, $invoice, $action, $payload, $correlationId, $actorUserId, $requestContext,
            ): array {
                $siteItems = $siteOrder->items()
                    ->lockForUpdate()
                    ->get();
                $affected  = $this->affectedItemsForCorrelation($siteItems, (int) $invoice->id, $correlationId);

                // Voiding the invoice debit affects the whole order even if no
                // item row itself changed in this request.
                if ( $action === 'customer_invoice_debit_voided' ) {
                    $affected = $siteItems;
                }

                if ( $affected->isEmpty() ) {
                    $key                               = $this->pendingKey($correlationId, (int) $invoice->id);
                    self::$pendingLedgerEvents[$key][] = [
                        'action'        => $action,
                        'payload'       => $payload,
                        'actor_user_id' => $actorUserId,
                        'request'       => $requestContext,
                    ];

                    return [
                        'status'            => 'deferred',
                        'reason'            => 'ledger_event_arrived_before_item_event',
                        'external_order_id' => (int) $siteOrder->id,
                    ];
                }

                $this->applyLedgerEventToItems($affected, $action, $payload, $correlationId, $actorUserId, $requestContext);

                return [
                    'status'              => 'synced',
                    'external_order_id'   => (int) $siteOrder->id,
                    'site_order_item_ids' => $affected->pluck('id')
                        ->map(fn( $id ) => (int) $id)
                        ->values()
                        ->all(),
                ];
            });
    }

    private function applyPendingLedgerEvents( Invoice $invoice, Collection $siteItems, string $correlationId ): void {
        $key     = $this->pendingKey($correlationId, (int) $invoice->id);
        $pending = self::$pendingLedgerEvents[$key] ?? [];

        foreach ( $pending as $row ) {
            $this->applyLedgerEventToItems($siteItems, $row['action'], $row['payload'], $correlationId, $row['actor_user_id'], $row['request']);
        }
    }

    private function applyLedgerEventToItems( Collection $siteItems, string $action, array $payload, string $correlationId, ?int $actorUserId, array $requestContext ): void {
        $ledger = data_get($payload, 'notes_candidate.financial_ledger', []);

        foreach ( $siteItems as $siteItem ) {
            if ( !$siteItem instanceof SiteOrderItem ) {
                continue;
            }

            $eventKey   = $this->eventKey($correlationId, $action, $payload, (int) $siteItem->id);
            $sectionKey = $action === 'customer_invoice_debit_voided' ? 'financial_ledger' : 'financial_ledger_raw';

            $siteItem->mergeErpNotes([
                $sectionKey => is_array($ledger) ? $ledger : [],
                'sync'      => [
                    'correlation_id' => $correlationId,
                    'last_action'    => $action,
                    'last_synced_at' => now()->toISOString(),
                ],
            ], $this->historyEntry($eventKey, $correlationId, $action, $actorUserId, $requestContext, $payload));
        }
    }

    private function findMatchingSiteOrderItem( Collection $siteItems, int $erpInvoiceItemId, int $sitePriceId, int $siteProductId, int $oldQuantity ): ?SiteOrderItem {
        if ( $erpInvoiceItemId > 0 ) {
            $byPreviousSync = $siteItems->first(function ( SiteOrderItem $item ) use ( $erpInvoiceItemId ): bool {
                return (int) data_get($item->notesArray(), 'sync.erp_invoice_item_id', 0) === $erpInvoiceItemId;
            });

            if ( $byPreviousSync ) {
                return $byPreviousSync;
            }
        }

        $candidates = $siteItems;

        if ( $sitePriceId > 0 ) {
            $byPrice = $candidates->filter(fn( SiteOrderItem $item ) => (int) $item->price_id === $sitePriceId);
            if ( $byPrice->count() === 1 ) {
                return $byPrice->first();
            }
            if ( $byPrice->isNotEmpty() ) {
                $candidates = $byPrice;
            }
        }

        if ( $siteProductId > 0 ) {
            $byProduct = $candidates->filter(fn( SiteOrderItem $item ) => (int) $item->product_id === $siteProductId);
            if ( $byProduct->count() === 1 ) {
                return $byProduct->first();
            }
            if ( $byProduct->isNotEmpty() ) {
                $candidates = $byProduct;
            }
        }

        if ( $oldQuantity > 0 ) {
            $byQuantity = $candidates->filter(fn( SiteOrderItem $item ) => (int) $item->quantity === $oldQuantity);
            if ( $byQuantity->count() === 1 ) {
                return $byQuantity->first();
            }
        }

        return $candidates->count() === 1 ? $candidates->first() : null;
    }

    private function createSiteOrderItemForAddedInvoiceItem( SiteOrder $siteOrder, Invoice $invoice, array $after, ?ProductVariant $variant, ?SitePrice $sitePrice, int $sitePriceId, int $siteProductId ): ?SiteOrderItem {
        $quantity = max((int) ( $after['quantity'] ?? 0 ), 0);
        if ( $quantity <= 0 || $sitePriceId <= 0 ) {
            return null;
        }

        if ( $siteProductId <= 0 && $sitePrice ) {
            $siteProductId = (int) $sitePrice->product_id;
        }
        if ( $siteProductId <= 0 ) {
            return null;
        }

        $grossUnit    = max((int) ( $after['price'] ?? 0 ), 0);
        $lineTotal    = max((int) ( $after['line_total'] ?? ( $quantity * $grossUnit ) ), 0);
        $finalUnit    = $quantity > 0 ? (int) floor($lineTotal / $quantity) : $grossUnit;
        $unitDiscount = max($grossUnit - $finalUnit, 0);

        return SiteOrderItem::query()
            ->create([
                'order_id'      => (int) $siteOrder->id,
                'product_id'    => $siteProductId,
                'title'         => (string) ( $variant?->product?->name ?? 'محصول افزوده‌شده در اصلاح فاکتور' ),
                'price'         => $finalUnit,
                'real_price'    => $grossUnit,
                'quantity'      => $quantity,
                'discount'      => $unitDiscount,
                'discount_type' => 'fixed',
                'price_id'      => $sitePriceId,
                'notes'         => [
                    'schema_version' => 1,
                    'sync'           => [
                        'created_from_erp_adjustment' => true,
                        'erp_invoice_id'              => (int) $invoice->id,
                    ],
                ],
            ]);
    }

    private function affectedItemsForCorrelation( Collection $siteItems, int $invoiceId, string $correlationId ): Collection {
        return $siteItems->filter(function ( SiteOrderItem $item ) use ( $invoiceId, $correlationId ): bool {
            $notes = $item->notesArray();

            return (int) data_get($notes, 'sync.erp_invoice_id', 0) === $invoiceId
                   && (string) data_get($notes, 'sync.correlation_id', '') === $correlationId;
        })
            ->values();
    }

    private function syncMetadata( Invoice $invoice, SiteOrder $siteOrder, SiteOrderItem $siteItem, int $erpInvoiceItemId, int $sitePriceId, int $siteProductId, string $correlationId, string $action ): array {
        return [
            'source'              => 'erp',
            'external_order_id'   => (int) $siteOrder->id,
            'erp_invoice_id'      => (int) $invoice->id,
            'erp_invoice_uuid'    => (string) $invoice->uuid,
            'erp_invoice_item_id' => $erpInvoiceItemId,
            'site_order_item_id'  => (int) $siteItem->id,
            'site_price_id'       => $sitePriceId,
            'site_product_id'     => $siteProductId,
            'correlation_id'      => $correlationId,
            'last_action'         => $action,
            'last_synced_at'      => now()->toISOString(),
        ];
    }

    private function historyEntry( string $eventKey, string $correlationId, string $action, ?int $actorUserId, array $requestContext, array $payload ): array {
        return [
            'event_key'      => $eventKey,
            'correlation_id' => $correlationId,
            'action'         => $action,
            'actor_user_id'  => $actorUserId,
            'request'        => $requestContext,
            'payload'        => $payload,
            'synced_at'      => now()->toISOString(),
        ];
    }

    private function eventKey( string $correlationId, string $action, array $payload, int $siteOrderItemId ): string {
        return hash('sha256', $correlationId . '|' . $action . '|' . $siteOrderItemId . '|' . json_encode($payload));
    }

    private function pendingKey( string $correlationId, int $invoiceId ): string {
        return $correlationId . ':' . $invoiceId;
    }

    private function resolveInvoiceId( array $payload ): int {
        $invoiceId = (int) ( $payload['invoice_id'] ?? 0 );
        if ( $invoiceId > 0 ) {
            return $invoiceId;
        }

        foreach ( [ 'after', 'before' ] as $side ) {
            $row           = is_array($payload[$side] ?? null) ? $payload[$side] : [];
            $referenceType = ltrim((string) ( $row['reference_type'] ?? '' ), '\\');
            $referenceId   = (int) ( $row['reference_id'] ?? 0 );

            if ( $referenceId > 0 && in_array($referenceType, [ Invoice::class, ltrim(Invoice::class, '\\') ], true) ) {
                return $referenceId;
            }
        }

        $candidate = data_get($payload, 'notes_candidate.financial_ledger.reference_id');
        $type      = ltrim((string) data_get($payload, 'notes_candidate.financial_ledger.reference_type', ''), '\\');

        if ( (int) $candidate > 0 && in_array($type, [ Invoice::class, ltrim(Invoice::class, '\\') ], true) ) {
            return (int) $candidate;
        }

        return 0;
    }

    private function positiveInteger( mixed $value ): int {
        return is_numeric($value) && (int) $value > 0 ? (int) $value : 0;
    }

    private function changeType( string $action, int $oldQuantity, int $newQuantity, array $before, array $after ): string {
        if ( $action === 'invoice_item_removed' || $newQuantity <= 0 ) {
            return 'removed';
        }
        if ( $action === 'invoice_item_added' || $oldQuantity <= 0 ) {
            return 'added';
        }
        if ( $newQuantity < $oldQuantity ) {
            return 'quantity_decreased';
        }
        if ( $newQuantity > $oldQuantity ) {
            return 'quantity_increased';
        }
        if ( ( $before['variant_id'] ?? null ) !== ( $after['variant_id'] ?? null ) ) {
            return 'variant_changed';
        }
        if ( (int) ( $before['price'] ?? 0 ) !== (int) ( $after['price'] ?? 0 )
             || (int) ( $before['line_discount_amount'] ?? 0 ) !== (int) ( $after['line_discount_amount'] ?? 0 ) ) {
            return 'price_adjusted';
        }

        return 'unchanged';
    }

    private function customerMessage( string $changeType, int $paidQuantity, int $approvedQuantity, int $unsentQuantity, int $addedQuantity, string $reason, string $note ): string {
        $message = match ( $changeType ) {
            'removed'            => 'این محصول در مرحله اصلاح فاکتور از ارسال حذف شده است.',
            'quantity_decreased' => "از {$paidQuantity} عدد سفارش داده‌شده، {$approvedQuantity} عدد تأیید و {$unsentQuantity} عدد ارسال نمی‌شود.",
            'quantity_increased' => $addedQuantity > 0 ? "تعداد این محصول در اصلاح فاکتور {$addedQuantity} عدد افزایش یافته است." : 'تعداد این محصول در اصلاح فاکتور تغییر کرده است.',
            'added'              => 'این محصول در مرحله اصلاح فاکتور به سفارش اضافه شده است.',
            'variant_changed'    => 'تنوع این محصول در مرحله اصلاح فاکتور تغییر کرده است.',
            'price_adjusted'     => 'قیمت یا تخفیف این محصول در مرحله اصلاح فاکتور تغییر کرده است.',
            default              => 'این محصول در مرحله اصلاح فاکتور تغییر کرده است.',
        };

        $reasonLabel = $this->reasonLabel($reason);
        if ( $reasonLabel !== '' ) {
            $message .= ' دلیل: ' . $reasonLabel . '.';
        }
        if ( $note !== '' ) {
            $message .= ' توضیح: ' . $note;
        }

        return trim($message);
    }

    private function reasonLabel( string $reason ): string {
        return match ( $reason ) {
            'customer_cancelled' => 'لغو یا کاهش به درخواست مشتری',
            'out_of_stock'       => 'عدم موجودی کالا',
            'damaged'            => 'ایراد یا آسیب کالا',
            'wrong_item'         => 'عدم تطابق کالا',
            'invoice_correction' => 'اصلاح فاکتور',
            ''                   => '',
            default              => str_replace('_', ' ', $reason),
        };
    }

    private function isWholeInvoiceStatus( string $status ): bool {
        return in_array($status, [
            Invoice::STATUS_NOT_SHIPPED,
            'cancelled',
            'canceled',
        ], true);
    }
}
