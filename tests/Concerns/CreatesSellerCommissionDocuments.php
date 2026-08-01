<?php

namespace Tests\Concerns;

use App\Models\Invoice;
use App\Models\PreinvoiceOrder;
use App\Models\SellerSalesDocument;
use App\Models\User;
use App\Services\Finance\SellerCommissionDocumentService;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

trait CreatesSellerCommissionDocuments
{
    protected function setUpCreatesSellerCommissionDocuments(): void
    {
        Http::preventStrayRequests();
        Mail::fake();
        Notification::fake();
        Queue::fake();
    }

    protected function financeActor(): User
    {
        $user = $this->erpUser(['name' => 'کاربر مالی']);
        $user->assignRole(Role::findOrCreate('Owner', 'web'));

        return $user;
    }

    protected function erpUser(array $attributes = []): User
    {
        return User::factory()->create(array_merge([
            'is_active' => true,
            'can_access_erp' => true,
            'is_seller' => false,
        ], $attributes));
    }

    protected function makePreinvoice(User $creator, string $createdAt = '2026-07-10 12:00:00', array $attributes = []): PreinvoiceOrder
    {
        $preinvoice = PreinvoiceOrder::query()->create(array_merge([
            'uuid' => (string) Str::uuid(),
            'created_by' => $creator->id,
            'status' => PreinvoiceOrder::STATUS_CONVERTED_TO_INVOICE,
            'customer_name' => 'مشتری آزمایشی',
            'customer_mobile' => '09120000000',
            'customer_address' => 'تهران',
            'province_id' => 1,
            'shipping_id' => 0,
            'shipping_price' => 0,
            'discount_amount' => 0,
            'total_price' => 1000,
        ], $attributes));

        $preinvoice->forceFill(['created_at' => $createdAt, 'updated_at' => '2026-08-01 12:00:00'])->saveQuietly();

        return $preinvoice->fresh();
    }

    protected function makeInvoice(User $owner, int $total = 1000, string $createdAt = '2026-07-10 12:00:00', array $attributes = []): Invoice
    {
        static $sequence = 10000;
        $preinvoice = $this->makePreinvoice($owner, $createdAt);
        $number = str_pad((string) (++$sequence), 5, '0', STR_PAD_LEFT);

        return Invoice::query()->create(array_merge([
            'uuid' => $number,
            'preinvoice_order_id' => $preinvoice->id,
            'seller_id' => null,
            'customer_name' => 'مشتری آزمایشی',
            'subtotal' => $total,
            'shipping_price' => 0,
            'discount_amount' => 0,
            'total' => $total,
            'status' => Invoice::STATUS_SHIPPED,
            'document_date' => '2026-07-20 10:00:00',
        ], $attributes));
    }

    protected function documentData(User $owner, array $invoices, array $overrides = []): array
    {
        return array_merge([
            'user_id' => $owner->id,
            'date_from' => '2026-07-01',
            'date_to' => '2026-07-31',
            'invoice_ids' => collect($invoices)->map(fn (Invoice $invoice) => $invoice->id)->all(),
            'notes' => 'یادداشت تست',
        ], $overrides);
    }

    protected function createCommissionDocument(User $owner, array $invoices, ?User $actor = null, array $overrides = []): SellerSalesDocument
    {
        return app(SellerCommissionDocumentService::class)->createDocument(
            $this->documentData($owner, $invoices, $overrides),
            $actor ?: $this->financeActor(),
        );
    }
}
