<?php

namespace App\Services;

use App\Models\{Customer, CustomerSaleApproval, PreinvoiceOrder, User};
use App\Support\{ActivityLogger, PhoneNormalizer};
use Illuminate\Support\Facades\{DB, Hash, RateLimiter};
use Illuminate\Validation\ValidationException;

/** One approval authorizes one customer's one sales document, never account access. */
class CustomerSaleAuthorization
{
    public function __construct(private readonly CustomerOwnership $ownership, private readonly CustomerSaleApprovalMessenger $messenger) {}

    public function authorizeRequester(User $actor): void
    {
        abort_unless($actor->is_active && $actor->can_access_erp && \App\Support\PageAccessCatalog::userCanRoute($actor, 'preinvoice.create'), 403);
    }

    private function fail(): never
    {
        throw ValidationException::withMessages(['customer_id' => 'ثبت برای این مشتری نیازمند تأیید مسئول فعلی مشتری است؛ کد مجوز را دریافت و تأیید کنید.']);
    }

    private function recipientHash(User $owner): string
    {
        return hash_hmac('sha256', PhoneNormalizer::normalize((string) $owner->phone), (string) config('app.key'));
    }

    private function currentOwner(Customer $customer, CustomerSaleApproval $approval): bool
    {
        $assignment = $customer->assignment()->first();
        $owner = $assignment?->seller;
        return $assignment && $assignment->seller_id === $approval->owner_id && $assignment->version === $approval->assignment_version
            && $owner && $owner->is_active && $owner->can_access_erp && hash_equals($approval->recipient_hash, $this->recipientHash($owner));
    }

    public function issue(Customer $customer, User $actor, string $context): CustomerSaleApproval
    {
        $this->authorizeRequester($actor);
        return DB::transaction(function () use ($customer, $actor, $context) {
            $customer = Customer::lockForUpdate()->findOrFail($customer->id);
            $assignment = $customer->assignment()->lockForUpdate()->first();
            $owner = $assignment?->seller;
            if (!$customer->is_active || !$owner || !$owner->is_active || !$owner->can_access_erp || $owner->id === $actor->id || $this->ownership->canSellAny($actor)) {
                throw ValidationException::withMessages(['customer_id' => 'این مشتری نیازمند مجوز همکار نیست یا مسئول فعال ندارد.']);
            }
            if (!preg_match('/^09\d{9}$/', PhoneNormalizer::normalize((string) $owner->phone))) {
                throw ValidationException::withMessages(['customer_id' => 'شماره همراه مسئول مشتری معتبر نیست؛ از مدیریت پیگیری کنید.']);
            }
            $existing = CustomerSaleApproval::where('requester_id', $actor->id)->where('context_token', $context)->where('customer_id', $customer->id)->lockForUpdate()->first();
            abort_if($existing && $existing->updated_at->gt(now()->subSeconds(60)), 429, 'برای ارسال مجدد یک دقیقه صبر کنید.');
            if ($existing?->bound_at) $this->fail();
            foreach (["sale-otp-requester:{$actor->id}" => 10, "sale-otp-owner:{$owner->id}" => 10] as $key => $max) {
                abort_if(RateLimiter::tooManyAttempts($key, $max), 429, 'تعداد درخواست کد بیش از حد مجاز است؛ بعداً تلاش کنید.');
                RateLimiter::hit($key, 3600);
            }
            $code = (string) random_int(1000, 9999);
            try { $this->messenger->send($owner, $actor, $customer, $code); }
            catch (\Throwable) { abort(503, 'ارسال کد انجام نشد؛ مجوزی صادر نشده است. دوباره تلاش کنید.'); }
            $approval = CustomerSaleApproval::updateOrCreate(['requester_id' => $actor->id, 'context_token' => $context, 'customer_id' => $customer->id], [
                'owner_id' => $owner->id, 'assignment_version' => $assignment->version, 'recipient_hash' => $this->recipientHash($owner),
                'code_hash' => Hash::make($code), 'attempts' => 0, 'code_expires_at' => now()->addMinutes(5), 'verified_at' => null, 'approval_expires_at' => null,
            ]);
            ActivityLogger::logForActor($actor->id, 'customer_sale_approval_requested', $approval, 'درخواست مجوز ثبت یک سند برای مشتری همکار.', ['customer_id' => $customer->id, 'owner_id' => $owner->id]);
            return $approval;
        });
    }

    public function verify(string $id, User $actor, string $context, string $code): CustomerSaleApproval
    {
        $this->authorizeRequester($actor);
        // Commit incorrect attempts before raising the validation error.
        $result = DB::transaction(function () use ($id, $actor, $context, $code) {
            $candidate = CustomerSaleApproval::where('requester_id', $actor->id)->where('context_token', $context)->findOrFail($id);
            $customer = Customer::lockForUpdate()->findOrFail($candidate->customer_id);
            $approval = CustomerSaleApproval::lockForUpdate()->findOrFail($id);
            if ($approval->verified_at || $approval->attempts >= 5 || $approval->code_expires_at->lte(now()) || !$customer->is_active || !$this->currentOwner($customer, $approval)) return null;
            $approval->increment('attempts');
            if (!Hash::check($code, $approval->code_hash)) return null;
            $approval->update(['verified_at' => now(), 'approval_expires_at' => now()->addMinutes(15)]);
            ActivityLogger::logForActor($actor->id, 'customer_sale_approval_verified', $approval, 'کد مسئول مشتری برای ثبت یک سند تأیید شد.', ['owner_id' => $approval->owner_id, 'customer_id' => $customer->id]);
            return $approval;
        });
        if (!$result) throw ValidationException::withMessages(['code' => 'کد نامعتبر، منقضی یا قبلاً استفاده شده است؛ حداکثر ۵ تلاش مجاز است.']);
        return $result;
    }

    public function assertCanSell(?Customer $customer, User $actor, ?string $context = null, ?PreinvoiceOrder $order = null, bool $read = false): ?CustomerSaleApproval
    {
        if ($this->ownership->canSellAny($actor) || ($customer && $actor->is_active && $actor->can_access_erp && $customer->assignment()->where('seller_id', $actor->id)->exists())) return null;
        if (!$customer || !$customer->is_active || !$actor->is_active || !$actor->can_access_erp) $this->fail();
        $approval = CustomerSaleApproval::where('requester_id', $actor->id)->where('customer_id', $customer->id)->whereNotNull('verified_at')
            ->where(function ($q) use ($context, $order, $actor) {
                if ($context) $q->where('context_token', $context);
                else $q->whereRaw('1=0');
                if ($order && (int) $order->created_by === (int) $actor->id) $q->orWhere('preinvoice_order_id', $order->id);
            })->orderByDesc('verified_at')->lockForUpdate()->first();
        if (!$approval || !$this->currentOwner($customer, $approval)) $this->fail();
        $this->authorizeRequester($actor);
        // A deleted draft retains its approval audit but never frees the approval for reuse.
        if ($approval->bound_at && !$approval->preinvoice_order_id) $this->fail();
        if ($approval->preinvoice_order_id !== null) {
            if (!$read && (!$order || $approval->preinvoice_order_id !== $order->id || (int) $order->created_by !== (int) $actor->id)) $this->fail();
        } elseif (!$approval->approval_expires_at || $approval->approval_expires_at->lte(now())) $this->fail();
        return $approval;
    }

    public function bind(PreinvoiceOrder $order, User $actor, ?string $context): void
    {
        // Empty autosave snapshots are drafts, not authorization to submit a sale.
        if (!$order->customer_id && $order->status === PreinvoiceOrder::STATUS_DRAFT) return;
        $approval = $this->assertCanSell(Customer::lockForUpdate()->find($order->customer_id), $actor, $context, $order);
        if (!$approval) return;
        if ($approval->preinvoice_order_id && $approval->preinvoice_order_id !== $order->id) $this->fail();
        // Autosave and submit hydrate seller_id independently. Keep the approved
        // requesting employee throughout both stages, even without the legacy flag.
        if ((int) $order->seller_id !== (int) $actor->id) $order->update(['seller_id' => $actor->id]);
        if (!$approval->bound_at) {
            // Approval changes neither customer ownership nor the commission policy:
            // the requesting employee is the seller of this newly authorized document.
            $approval->update(['preinvoice_order_id' => $order->id, 'bound_at' => now()]);
            ActivityLogger::logForActor($actor->id, 'customer_sale_approval_bound', $approval, 'مجوز همکار به یک پیش‌فاکتور متصل شد؛ مالکیت مشتری تغییر نکرد.', ['preinvoice_order_id' => $order->id, 'seller_id' => $order->seller_id, 'owner_id' => $approval->owner_id]);
        }
        if ($order->status === PreinvoiceOrder::STATUS_PENDING_FINANCE && !$approval->submitted_at) $approval->update(['submitted_at' => now()]);
    }
}
