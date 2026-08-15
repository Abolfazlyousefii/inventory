<?php

namespace App\Services\Commissions;

use App\Models\CommissionPayment;
use App\Models\CommissionPeriod;
use App\Models\CommissionSettlement;
use App\Models\User;
use App\Support\ActivityLogger;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CommissionPaymentService
{
    public function record(CommissionSettlement $settlement, array $data, User $actor): CommissionPayment
    {
        return DB::transaction(function () use ($settlement, $data, $actor) {
            $settlement = CommissionSettlement::query()->with('period')->lockForUpdate()->findOrFail($settlement->id);
            if ($settlement->period->status !== CommissionPeriod::STATUS_CLOSED || $settlement->net_payable <= 0) {
                throw ValidationException::withMessages(['settlement' => 'این تسویه در وضعیت قابل پرداخت نیست.']);
            }
            $amount = (int) $data['amount'];
            if ($amount <= 0) {
                throw ValidationException::withMessages(['amount' => 'مبلغ پرداخت باید بزرگ‌تر از صفر باشد.']);
            }
            if (! empty($data['idempotency_key'])) {
                $existing = CommissionPayment::query()->where('idempotency_key', $data['idempotency_key'])->first();
                if ($existing) {
                    if ((int) $existing->commission_settlement_id !== (int) $settlement->id || (int) $existing->amount !== $amount) {
                        throw ValidationException::withMessages(['idempotency_key' => 'کلید تکرار با درخواست مالی دیگری استفاده شده است.']);
                    }

                    return $existing;
                }
            }
            $paid = (int) $settlement->payments()->where('status', CommissionPayment::STATUS_RECORDED)->sum('amount');
            if ($paid + $amount > $settlement->net_payable) {
                throw ValidationException::withMessages(['amount' => 'مبلغ پرداخت از مانده تسویه بیشتر است.']);
            }
            $payment = $settlement->payments()->create([
                'seller_id' => $settlement->seller_id, 'commission_period_id' => $settlement->commission_period_id,
                'idempotency_key' => $data['idempotency_key'] ?? null, 'amount' => $amount, 'paid_at' => $data['paid_at'],
                'payment_method' => $data['payment_method'] ?? null, 'reference_number' => $data['reference_number'] ?? null,
                'notes' => $data['notes'] ?? null, 'status' => CommissionPayment::STATUS_RECORDED, 'created_by' => $actor->id,
            ]);
            $this->sync($settlement);
            ActivityLogger::log('payment_recorded', $payment, 'پرداخت پورسانت ثبت شد.', ['amount' => $amount, 'settlement_id' => $settlement->id]);

            return $payment->fresh();
        }, 3);
    }

    public function void(CommissionPayment $payment, string $reason, User $actor): CommissionPayment
    {
        return DB::transaction(function () use ($payment, $reason, $actor) {
            $payment = CommissionPayment::query()->with('settlement.period')->lockForUpdate()->findOrFail($payment->id);
            if ($payment->settlement->period->status === CommissionPeriod::STATUS_PAID) {
                throw ValidationException::withMessages(['payment' => 'ابطال پرداخت دوره پرداخت‌شده مجاز نیست؛ اصلاح باید در دوره بعد ثبت شود.']);
            }
            if ($payment->status !== CommissionPayment::STATUS_RECORDED || trim($reason) === '') {
                throw ValidationException::withMessages(['reason' => 'پرداخت فعال و دلیل ابطال معتبر لازم است.']);
            }
            $payment->update(['status' => CommissionPayment::STATUS_VOID, 'void_reason' => trim($reason), 'voided_by' => $actor->id, 'voided_at' => now()]);
            $this->sync($payment->settlement);
            ActivityLogger::log('payment_voided', $payment, 'پرداخت پورسانت با حفظ سابقه باطل شد.', ['reason' => $reason]);

            return $payment->fresh();
        }, 3);
    }

    public function sync(CommissionSettlement $settlement): CommissionSettlement
    {
        $paid = (int) $settlement->payments()->where('status', CommissionPayment::STATUS_RECORDED)->sum('amount');
        $remaining = max(0, $settlement->net_payable - $paid);
        $status = $settlement->net_payable <= 0
            ? ($settlement->net_payable < 0 ? CommissionSettlement::STATUS_CREDIT_CARRIED : CommissionSettlement::STATUS_ZERO)
            : ($remaining === 0 ? CommissionSettlement::STATUS_PAID : ($paid > 0 ? CommissionSettlement::STATUS_PARTIALLY_PAID : CommissionSettlement::STATUS_UNPAID));
        $settlement->update(['paid_amount' => $paid, 'remaining_amount' => $remaining, 'status' => $status,
            'fully_paid_at' => $status === CommissionSettlement::STATUS_PAID ? ($settlement->fully_paid_at ?? now()) : null]);

        return $settlement->fresh();
    }
}
