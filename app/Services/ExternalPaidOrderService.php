<?php

namespace App\Services;

use App\Models\City;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Province;
use App\Support\DocumentCodeGenerator;
use Carbon\Carbon;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ExternalPaidOrderService
{
    public function __construct(
        private readonly CustomerLedgerService $customerLedgerService,
        private readonly PaymentRegistrationService $paymentRegistrationService,
        private readonly SalesHavalehHistoryService $historyService,
    ) {}

    /**
     * @return array{invoice: Invoice, created: bool}
     */
    public function import(array $payload, bool $startCollection = true): array
    {
        $externalOrderId = (int) $payload['crm_order_id'];
        $existing = Invoice::query()->where('external_order_id', $externalOrderId)->first();

        if ($existing) {
            return ['invoice' => $existing, 'created' => false];
        }

        try {
            return DB::transaction(function () use ($payload, $externalOrderId, $startCollection): array {
                $existing = Invoice::query()
                    ->where('external_order_id', $externalOrderId)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    return ['invoice' => $existing, 'created' => false];
                }

                $resolvedItems = $this->resolveItems($payload['items']);
                $invoiceNumber = DocumentCodeGenerator::generateUnique5DigitCode(Invoice::class);

                // Generating the official number serializes concurrent invoice
                // creation. Recheck the idempotency key after acquiring that lock.
                $existing = Invoice::query()
                    ->where('external_order_id', $externalOrderId)
                    ->lockForUpdate()
                    ->first();

                if ($existing) {
                    return ['invoice' => $existing, 'created' => false];
                }

                $shippingAddress = $this->shippingAddress($payload);
                $customer = $this->resolveCustomer($payload, $shippingAddress);
                $totals = $this->calculateTotals($payload, $resolvedItems);
                $occurredAt = $this->occurredAt($payload);
                [$provinceId, $cityId] = $this->resolveLocationIds($shippingAddress);

                $initialStatus = $startCollection
                    ? Invoice::STATUS_COLLECTING
                    : Invoice::STATUS_PENDING_COLLECTION;

                $invoice = Invoice::create([
                    'uuid' => $invoiceNumber,
                    'external_order_id' => $externalOrderId,
                    'document_date' => $occurredAt,
                    'customer_id' => $customer->id,
                    'customer_name' => $this->customerName($payload, $shippingAddress, $customer),
                    'customer_mobile' => $customer->mobile,
                    'customer_address' => $this->addressText($shippingAddress, $customer),
                    'province_id' => $provinceId,
                    'city_id' => $cityId,
                    'shipping_price' => $totals['shipping'],
                    'discount_amount' => $totals['total_discount'],
                    'invoice_discount_type' => $totals['invoice_discount'] > 0 ? 'amount' : null,
                    'invoice_discount_value' => $totals['invoice_discount'],
                    'invoice_discount_amount' => $totals['invoice_discount'],
                    'product_discount_amount' => $totals['product_discount'],
                    'discount_allocation_mode' => 'separate',
                    'subtotal' => $totals['subtotal'],
                    'total' => $totals['total'],
                    'status' => $initialStatus,
                    'status_changed_at' => now(),
                    'warehouse_received_at' => $startCollection ? now() : null,
                    'collection_started_at' => $startCollection ? now() : null,
                ]);

                foreach ($resolvedItems as $index => $row) {
                    $invoice->items()->create([
                        'product_id' => (int) $row['variant']->product_id,
                        'variant_id' => (int) $row['variant']->id,
                        'quantity' => $row['quantity'],
                        'price' => $row['price'],
                        'line_total' => $row['line_total'],
                        'sort_order' => $index + 1,
                        'line_discount_amount' => $row['discount'],
                    ]);
                }

                $this->consumeStock($resolvedItems);
                $this->customerLedgerService->syncInvoiceDebit($invoice);

                if ($invoice->total > 0) {
                    $this->paymentRegistrationService->registerForInvoice(
                        $invoice,
                        [
                            'method' => 'online',
                            'amount' => (int) $invoice->total,
                            'paid_at' => $occurredAt->toDateString(),
                            'payment_identifier' => $this->paymentIdentifier($payload),
                            'note' => 'پرداخت سفارش سایت #'.$externalOrderId,
                        ],
                        (int) $customer->id
                    );
                }

                $this->historyService->log(
                    $invoice,
                    'external_paid_order_imported',
                    'status',
                    null,
                    $initialStatus,
                    $startCollection
                        ? "سفارش پرداخت‌شده سایت #{$externalOrderId} دریافت و جمع‌آوری آن آغاز شد."
                        : "سفارش پرداخت‌شده سایت #{$externalOrderId} دریافت و در صف جمع‌آوری قرار گرفت."
                );

                return [
                    'invoice' => $invoice->fresh(['items.product', 'items.variant', 'payments']),
                    'created' => true,
                ];
            }, 3);
        } catch (QueryException $exception) {
            // The unique index remains the final idempotency guard for the
            // uncommon case where two servers receive the same event together.
            $existing = Invoice::query()->where('external_order_id', $externalOrderId)->first();

            if ($existing) {
                return ['invoice' => $existing, 'created' => false];
            }

            throw $exception;
        }
    }

    /**
     * @return array<int, array{variant: ProductVariant, quantity: int, price: int, discount: int, line_total: int}>
     */
    private function resolveItems(array $items): array
    {
        $resolved = [];

        foreach (array_values($items) as $index => $item) {
            $variant = $this->resolveVariant($item, $index);
            $quantity = (int) $item['quantity'];
            $price = (int) $item['price'];
            $gross = $quantity * $price;
            $discount = min(max((int) ($item['discount_amount'] ?? 0), 0), $gross);

            $resolved[] = [
                'variant' => $variant,
                'quantity' => $quantity,
                'price' => $price,
                'discount' => $discount,
                'line_total' => max($gross - $discount, 0),
            ];
        }

        return $resolved;
    }

    private function resolveVariant(array $item, int $index): ProductVariant
    {
        $siteVariantId = $this->firstPositiveInteger($item, [
            'price_variant.variety_id',
            'price_variant.id',
            'variety_id',
            'price_id',
            'get_price_id',
        ]);

        $query = ProductVariant::query()->with('product')->lockForUpdate();
        $variant = $siteVariantId ? (clone $query)->where('variety_id', $siteVariantId)->first() : null;

        $inventoryVariantId = $this->firstPositiveInteger($item, [
            'inventory_variant_id',
            'price_variant.inventory_variant_id',
        ]);

        if (! $variant && $inventoryVariantId) {
            $variant = (clone $query)->whereKey($inventoryVariantId)->first();
        }

        if (! $variant) {
            $uniqueKey = trim((string) data_get($item, 'price_variant.unique_key', ''));
            if ($uniqueKey !== '') {
                $variant = (clone $query)->where('unique_key', $uniqueKey)->first();
            }
        }

        if (! $variant) {
            $code = $this->firstNonEmptyString($item, [
                'price_variant.variant_code',
                'price_variant.variety_code',
                'price_variant.sku',
                'price_variant.barcode',
                'variant_code',
                'stock_code',
            ]);

            if ($code !== null) {
                $variant = (clone $query)->where(function ($codeQuery) use ($code) {
                    $codeQuery->where('variant_code', $code)
                        ->orWhere('variety_code', $code);
                })->first();
            }
        }

        if (! $variant) {
            $title = trim((string) ($item['title'] ?? ''));
            $product = $title !== ''
                ? Product::query()->where('name', $title)->first()
                : null;

            if ($product && $product->variants()->count() === 1) {
                $variant = $product->variants()->with('product')->lockForUpdate()->first();
            }
        }

        if (! $variant) {
            throw ValidationException::withMessages([
                "items.{$index}.price_variant" => 'تنوع این ردیف در انبار پیدا نشد. شناسه price_variant.id باید با variety_id تنوع انبار یکسان باشد.',
            ]);
        }

        $siteProductId = $this->firstPositiveInteger($item, [
            'product.id',
            'product_id',
        ]);
        $inventoryExternalProductId = trim((string) ($variant->product?->external_id ?? ''));

        if ($siteProductId && $inventoryExternalProductId !== '' && $inventoryExternalProductId !== (string) $siteProductId) {
            throw ValidationException::withMessages([
                "items.{$index}.product" => 'تنوع ارسال‌شده متعلق به کالای ارسال‌شده نیست.',
            ]);
        }

        return $variant;
    }

    private function consumeStock(array $resolvedItems): void
    {
        $quantities = [];
        $variants = [];

        foreach ($resolvedItems as $row) {
            $variantId = (int) $row['variant']->id;
            $quantities[$variantId] = ($quantities[$variantId] ?? 0) + (int) $row['quantity'];
            $variants[$variantId] = $row['variant'];
        }

        foreach ($quantities as $variantId => $quantity) {
            /** @var ProductVariant $variant */
            $variant = $variants[$variantId];

            WarehouseStockService::change(
                WarehouseStockService::centralWarehouseId(),
                (int) $variant->product_id,
                -$quantity,
                (int) $variant->id
            );
        }
    }

    private function resolveCustomer(array $payload, array $shippingAddress): Customer
    {
        $user = $this->userPayload($payload);
        $mobile = $this->normalizeMobile((string) (
            Arr::get($shippingAddress, 'mobile')
            ?: Arr::get($user, 'mobile')
            ?: Arr::get($user, 'phone')
            ?: Arr::get($user, 'username')
        ));

        if ($mobile === null) {
            throw ValidationException::withMessages([
                'shipping_address.mobile' => 'شماره موبایل گیرنده معتبر نیست.',
            ]);
        }

        $crmUserId = trim((string) (Arr::get($user, 'crm_user_id') ?: Arr::get($user, 'id')));
        [$firstName, $lastName] = $this->resolveName($user, $shippingAddress);

        $customer = null;
        if ($crmUserId !== '') {
            $customer = Customer::query()
                ->where('crm_customer_id', $crmUserId)
                ->lockForUpdate()
                ->first();
        }

        $mobileCustomer = Customer::query()->where('mobile', $mobile)->lockForUpdate()->first();

        if ($customer && $mobileCustomer && $customer->id !== $mobileCustomer->id) {
            throw ValidationException::withMessages([
                'user.crm_user_id' => 'شناسه کاربر و شماره موبایل به دو مشتری متفاوت در انبار متصل هستند.',
            ]);
        }

        $customer ??= $mobileCustomer ?? new Customer;
        $created = ! $customer->exists;
        [$provinceId, $cityId] = $this->resolveLocationIds($shippingAddress);

        $customer->fill([
            'crm_customer_id' => $crmUserId !== '' ? $crmUserId : $customer->crm_customer_id,
            'sync_source' => 'store_order',
            'first_name' => $firstName ?: ($customer->first_name ?: 'بدون نام'),
            'last_name' => $lastName ?: $customer->last_name,
            'mobile' => $mobile,
            'address' => $this->addressText($shippingAddress, $customer),
            'postal_code' => Arr::get($shippingAddress, 'postal_code') ?: $customer->postal_code,
            'province_id' => $provinceId ?: $customer->province_id,
            'city_id' => $cityId ?: $customer->city_id,
            'synced_at' => now(),
            'crm_updated_at' => Arr::get($user, 'updated_at'),
            'last_crm_payload' => collect($user)->except(['password', 'password_hash'])->all(),
        ]);

        if ($created) {
            $customer->reservation_tier = 'new_or_low_purchase';
        }

        $customer->save();

        return $customer;
    }

    private function calculateTotals(array $payload, array $items): array
    {
        $subtotal = (int) collect($items)->sum(
            fn (array $row): int => $row['quantity'] * $row['price']
        );
        $productDiscount = min(
            $subtotal,
            (int) collect($items)->sum(fn (array $row): int => $row['discount'])
        );
        $order = (array) ($payload['order'] ?? []);
        $shipping = $this->firstMoney($order, [
            'shipping_price',
            'shipping_amount',
            'carrier_price',
            'delivery_price',
            'postage',
            'carrier.price',
        ]) ?? 0;
        $declaredDiscount = $this->firstMoney($order, [
            'discount_amount',
            'discount_price',
            'discount.amount',
        ]);
        $declaredTotal = $this->firstMoney($order, [
            'payable_amount',
            'payable_price',
            'final_price',
            'total_price',
            'total',
            'amount',
            'price',
        ]);

        if ($declaredTotal !== null && $declaredTotal <= $subtotal + $shipping) {
            $totalDiscount = min($subtotal, max(($subtotal + $shipping) - $declaredTotal, 0));
        } else {
            $totalDiscount = min($subtotal, max($declaredDiscount ?? $productDiscount, $productDiscount));
        }

        $invoiceDiscount = max($totalDiscount - $productDiscount, 0);

        return [
            'subtotal' => $subtotal,
            'product_discount' => $productDiscount,
            'invoice_discount' => $invoiceDiscount,
            'total_discount' => $totalDiscount,
            'shipping' => $shipping,
            'total' => max($subtotal - $totalDiscount, 0) + $shipping,
        ];
    }

    private function shippingAddress(array $payload): array
    {
        foreach ([
            Arr::get($payload, 'shipping_address'),
            Arr::get($payload, 'order.shipping_address'),
            Arr::get($payload, 'order.address_details'),
            Arr::get($payload, 'address'),
        ] as $candidate) {
            if (is_array($candidate) && $candidate !== []) {
                return $candidate;
            }
        }

        return [];
    }

    private function userPayload(array $payload): array
    {
        $user = Arr::get($payload, 'user');

        if (! is_array($user) || $user === []) {
            $user = Arr::get($payload, 'customer', []);
        }

        return is_array($user) ? $user : [];
    }

    private function customerName(array $payload, array $shippingAddress, Customer $customer): string
    {
        $name = trim((string) Arr::get($shippingAddress, 'name'));

        if ($name !== '') {
            return $name;
        }

        $user = $this->userPayload($payload);
        $name = trim((string) Arr::get($user, 'name'));

        return $name !== '' ? $name : ($customer->display_name ?: 'مشتری سایت');
    }

    private function resolveName(array $user, array $shippingAddress): array
    {
        $firstName = trim((string) Arr::get($user, 'first_name'));
        $lastName = trim((string) Arr::get($user, 'last_name'));
        $fullName = trim((string) (
            Arr::get($user, 'name')
            ?: Arr::get($shippingAddress, 'name')
        ));

        if ($firstName === '' && $fullName !== '') {
            $parts = preg_split('/\s+/u', $fullName, 2) ?: [];
            $firstName = $parts[0] ?? '';
            $lastName = $lastName ?: ($parts[1] ?? '');
        }

        return [$firstName, $lastName ?: null];
    }

    private function resolveLocationIds(array $address): array
    {
        $province = null;
        $provinceName = trim((string) Arr::get($address, 'province.name'));

        if ($provinceName !== '') {
            $province = Province::query()->where('name', $provinceName)->first();
        }

        $provinceId = (int) Arr::get($address, 'province_id');
        if (! $province && $provinceId > 0) {
            $province = Province::query()->find($provinceId);
        }

        $city = null;
        $cityName = trim((string) Arr::get($address, 'city.name'));

        if ($cityName !== '') {
            $city = City::query()
                ->where('name', $cityName)
                ->when($province, fn ($query) => $query->where('province_id', $province->id))
                ->first();
        }

        $cityId = (int) Arr::get($address, 'city_id');
        if (! $city && $cityId > 0) {
            $city = City::query()
                ->when($province, fn ($query) => $query->where('province_id', $province->id))
                ->find($cityId);
        }

        return [$province?->id, $city?->id];
    }

    private function addressText(array $shippingAddress, Customer $customer): string
    {
        $address = trim((string) (
            Arr::get($shippingAddress, 'address')
            ?: Arr::get($shippingAddress, 'full_address')
            ?: $customer->address
        ));

        return $address !== '' ? $address : '—';
    }

    private function occurredAt(array $payload): Carbon
    {
        $value = Arr::get($payload, 'occurred_at')
            ?: Arr::get($payload, 'order.paid_at')
            ?: Arr::get($payload, 'order.updated_at')
            ?: Arr::get($payload, 'order.created_at');

        return $value ? Carbon::parse($value) : now();
    }

    private function paymentIdentifier(array $payload): ?string
    {
        $transactions = Arr::get($payload, 'order.transactions', []);

        if (! is_array($transactions)) {
            return null;
        }

        foreach ($transactions as $transaction) {
            if (! is_array($transaction)) {
                continue;
            }

            $identifier = $this->firstNonEmptyString($transaction, [
                'reference_id',
                'tracking_code',
                'track_id',
                'transaction_id',
                'ref_id',
                'id',
            ]);

            if ($identifier !== null) {
                return $identifier;
            }
        }

        return null;
    }

    private function normalizeMobile(string $value): ?string
    {
        $value = strtr($value, [
            '۰' => '0', '۱' => '1', '۲' => '2', '۳' => '3', '۴' => '4',
            '۵' => '5', '۶' => '6', '۷' => '7', '۸' => '8', '۹' => '9',
            '٠' => '0', '١' => '1', '٢' => '2', '٣' => '3', '٤' => '4',
            '٥' => '5', '٦' => '6', '٧' => '7', '٨' => '8', '٩' => '9',
        ]);
        $value = preg_replace('/\D+/', '', $value) ?? '';

        if (str_starts_with($value, '0098')) {
            $value = '0'.substr($value, 4);
        } elseif (str_starts_with($value, '98')) {
            $value = '0'.substr($value, 2);
        } elseif (strlen($value) === 10 && str_starts_with($value, '9')) {
            $value = '0'.$value;
        }

        return preg_match('/^09\d{9}$/', $value) ? $value : null;
    }

    private function firstPositiveInteger(array $payload, array $paths): ?int
    {
        foreach ($paths as $path) {
            $value = data_get($payload, $path);

            if (is_numeric($value) && (int) $value > 0) {
                return (int) $value;
            }
        }

        return null;
    }

    private function firstNonEmptyString(array $payload, array $paths): ?string
    {
        foreach ($paths as $path) {
            $value = trim((string) data_get($payload, $path, ''));

            if ($value !== '') {
                return $value;
            }
        }

        return null;
    }

    private function firstMoney(array $payload, array $paths): ?int
    {
        foreach ($paths as $path) {
            $value = data_get($payload, $path);

            if (is_numeric($value)) {
                return max((int) $value, 0);
            }
        }

        return null;
    }
}
