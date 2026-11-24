<?php

namespace App\Services;

use App\Models\Customer;
use App\Models\CustomerAssignment;
use App\Models\User;
use App\Support\ActivityLogger;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CustomerOwnership
{
    public function canManage(User $actor): bool
    {
        return $actor->is_active && $actor->can_access_erp && ($actor->isAdministrator() || $actor->hasPermission('commercial.customers.transfer'));
    }

    public function canSellAny(User $actor): bool
    {
        return $actor->is_active && $actor->can_access_erp && ($actor->isAdministrator() || $actor->hasPermission('commercial.customers.sell_any'));
    }

    public function visible(Builder $query, User $actor): Builder
    {
        return $this->canSellAny($actor) ? $query : $query->whereHas('assignment', fn ($q) => $q->where('seller_id', $actor->id));
    }

    public function assertCanSell(?Customer $customer, User $actor): void
    {
        if ($this->canSellAny($actor)) {
            return;
        }
        if (! $actor->is_active || ! $actor->can_access_erp || ! $customer || ! $customer->assignment()->where('seller_id', $actor->id)->lockForUpdate()->first()) {
            throw ValidationException::withMessages(['customer_id' => 'برای ثبت فاکتور، مشتری باید به شما تخصیص داده شده باشد. از مسئول انتقال مشتری پیگیری کنید.']);
        }
    }

    public function transfer(Customer $customer, User $seller, User $actor, int $version, string $reason): CustomerAssignment
    {
        abort_unless($this->canManage($actor), 403);
        if (! $seller->is_active || ! $seller->can_access_erp) {
            throw ValidationException::withMessages(['seller_id' => 'فروشنده مقصد باید فعال و دارای ورود به نرم‌افزار باشد.']);
        }
        if (trim($reason) === '') {
            throw ValidationException::withMessages(['reason' => 'دلیل انتقال را وارد کنید.']);
        }

        return DB::transaction(function () use ($customer, $seller, $actor, $version, $reason) {
            $locked = Customer::query()->lockForUpdate()->findOrFail($customer->id);
            $assignment = $locked->assignment()->lockForUpdate()->first();
            abort_unless(($assignment?->version ?? 0) === $version, 409, 'مسئول مشتری تغییر کرده است؛ فهرست را تازه کنید.');
            if ($assignment && $assignment->seller_id === $seller->id) {
                return $assignment;
            }
            $previous = $assignment?->seller_id;
            $assignment = $locked->assignment()->updateOrCreate([], ['seller_id' => $seller->id, 'version' => $version + 1]);
            $movedFollowups = \App\Models\CustomerFollowup::where('customer_id',$locked->id)->where('status','open')
                ->update(['assigned_to'=>$seller->id,'version'=>\Illuminate\Support\Facades\DB::raw('version + 1'),'reminded_at'=>null,'updated_at'=>now()]);
            if ($movedFollowups) ActivityLogger::logForActor($actor->id,'customer_open_followups_transferred',$locked,'انتقال پیگیری‌های باز همراه مسئول مشتری',['old_seller_id'=>$previous,'new_seller_id'=>$seller->id,'count'=>$movedFollowups]);
            ActivityLogger::logForActor($actor->id, 'customer_seller_transferred', $locked, 'تغییر مسئول مشتری؛ فاکتورهای قبلی تغییر نکردند.', ['old_seller_id' => $previous, 'new_seller_id' => $seller->id, 'reason' => trim($reason), 'version' => $assignment->version]);

            return $assignment;
        });
    }
}
