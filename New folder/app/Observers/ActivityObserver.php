<?php

namespace App\Observers;

use App\Models\Category;
use App\Models\Cheque;
use App\Models\Customer;
use App\Models\Invoice;
use App\Models\InvoiceItem;
use App\Models\InvoiceNote;
use App\Models\InvoicePayment;
use App\Models\PreinvoiceOrder;
use App\Models\PreinvoiceOrderItem;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use App\Support\ActivityLogger;
use Illuminate\Database\Eloquent\Model;

class ActivityObserver
{
    public function created(Model $model): void
    {
        ActivityLogger::log('created', $model, $this->description('created', $model), [
            'attributes' => $model->getAttributes(),
        ]);
    }

    public function updated(Model $model): void
    {
        ActivityLogger::log('updated', $model, $this->description('updated', $model), [
            'changes' => $model->getChanges(),
            'original' => $model->getOriginal(),
        ]);
    }

    public function deleted(Model $model): void
    {
        ActivityLogger::log('deleted', $model, $this->description('deleted', $model), [
            'attributes' => $model->getOriginal(),
        ]);
    }

    private function description(string $action, Model $model): string
    {
        $verbs = [
            'created' => 'ایجاد شد',
            'updated' => 'ویرایش شد',
            'deleted' => 'حذف شد',
        ];

        return match (true) {
            $model instanceof InvoiceItem => $this->invoiceItemDescription($action, $model, $verbs[$action]),
            $model instanceof Product => "محصول {$this->productTitle($model)} {$verbs[$action]}",
            $model instanceof ProductVariant => "مدل/واریانت محصول (شناسه {$model->id}) {$verbs[$action]}",
            $model instanceof Customer => "مشتری {$this->customerTitle($model)} {$verbs[$action]}",
            $model instanceof Invoice => "فاکتور {$model->uuid} برای {$model->customer_name} {$verbs[$action]}",
            $model instanceof InvoicePayment => "پرداخت {$model->amount} برای فاکتور {$model->invoice?->uuid} {$verbs[$action]}",
            $model instanceof InvoiceNote => "یادداشت فاکتور {$model->invoice?->uuid} {$verbs[$action]}",
            $model instanceof Cheque => "چک شماره {$model->cheque_number} {$verbs[$action]}",
            $model instanceof Category => "دسته‌بندی {$model->name} {$verbs[$action]}",
            $model instanceof StockMovement => "گردش انبار محصول {$model->product?->name} ({$model->type}) {$verbs[$action]}",
            $model instanceof PreinvoiceOrder => "پیش‌فاکتور {$model->uuid} برای {$model->customer_name} {$verbs[$action]}",
            $model instanceof PreinvoiceOrderItem => "آیتم پیش‌فاکتور {$model->order?->uuid} {$verbs[$action]}",
            default => class_basename($model) . ' ' . $verbs[$action],
        };
    }

    private function invoiceItemDescription(string $action, InvoiceItem $item, string $verb): string
    {
        $invoice = $item->invoice;
        $productName = $item->product?->name ?? 'محصول نامشخص';
        $variant = $item->variant?->variant_name ?? $item->variant_id;

        if ($action === 'created') {
            return "محصول {$productName} (مدل {$variant}) با تعداد {$item->quantity} به فاکتور {$invoice?->uuid} مشتری {$invoice?->customer_name} اضافه شد";
        }

        return "آیتم محصول {$productName} در فاکتور {$invoice?->uuid} {$verb}";
    }

    private function productTitle(Product $product): string
    {
        return trim($product->name . ' ' . ($product->sku ? "(SKU: {$product->sku})" : ''));
    }

    private function customerTitle(Customer $customer): string
    {
        return trim("{$customer->first_name} {$customer->last_name}");
    }
}
