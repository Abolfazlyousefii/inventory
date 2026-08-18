<?php

namespace App\Http\Controllers;

use App\Models\CommissionDocument;
use App\Models\CommissionDocumentAdjustment;
use App\Models\CommissionPayment;
use App\Models\CommissionPeriod;
use App\Models\CommissionSettlement;
use App\Services\Commissions\CommissionAdjustmentService;
use App\Services\Commissions\CommissionFeatureService;
use App\Services\Commissions\CommissionPaymentService;
use App\Services\Commissions\CommissionPeriodWorkflowService;
use App\Support\Currency;
use App\Support\JalaliDate;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

class CommissionSettlementController extends Controller
{
    public function startReview(Request $request, CommissionPeriod $period, CommissionPeriodWorkflowService $service): RedirectResponse
    {
        $this->authorizeAction($request, 'commissions.close_periods');
        $service->startReview($period, $request->user());

        return back()->with('success', 'دوره وارد مرحله بررسی شد.');
    }

    public function closePeriod(Request $request, CommissionPeriod $period, CommissionPeriodWorkflowService $service): RedirectResponse
    {
        $this->authorizeAction($request, 'commissions.close_periods');
        $service->close($period, $request->user());

        return back()->with('success', 'دوره بسته و تسویه‌ها ایجاد شدند.');
    }

    public function markPaid(Request $request, CommissionPeriod $period, CommissionPeriodWorkflowService $service): RedirectResponse
    {
        $this->authorizeAction($request, 'commissions.mark_period_paid');
        $service->markPaid($period, $request->user());

        return back()->with('success', 'دوره پرداخت‌شده و قفل نهایی شد.');
    }

    public function storeAdjustment(Request $request, CommissionAdjustmentService $service): RedirectResponse
    {
        $this->authorizeAction($request, 'commissions.manage_adjustments');
        $data = $request->validate(['seller_id' => ['required', 'integer', 'exists:users,id'], 'commission_period_id' => ['required', 'integer', 'exists:commission_periods,id'],
            'source_period_id' => ['nullable', 'integer', 'exists:commission_periods,id'], 'amount' => ['nullable', 'integer', 'required_without:amount_toman', 'not_in:0'],
            'amount_toman' => ['nullable', 'string', 'required_without:amount', 'max:30'],
            'reason' => ['required', 'string', 'max:5000'], 'source_reference' => ['nullable', 'string', 'max:255']]);
        $data['amount'] = array_key_exists('amount_toman', $data)
            ? Currency::signedTomanInput($data['amount_toman'])
            : (int) $data['amount'];
        unset($data['amount_toman']);
        if ($data['amount'] === 0) {
            return back()->withErrors(['amount_toman' => 'مبلغ تعدیل نباید صفر باشد.'])->withInput();
        }
        $service->create($data, $request->user());

        return back()->with('success', 'تعدیل پورسانت در انتظار بررسی ثبت شد.');
    }

    public function approveAdjustment(Request $request, CommissionDocument $document, CommissionDocumentAdjustment $adjustment, CommissionAdjustmentService $service): RedirectResponse
    {
        abort_unless((int) $adjustment->commission_document_id === (int) $document->id, 404);
        $this->authorizeAction($request, 'commissions.review_adjustments');
        $service->review($adjustment, $request->user(), true);

        return back()->with('success', 'تعدیل تأیید شد.');
    }

    public function rejectAdjustment(Request $request, CommissionDocument $document, CommissionDocumentAdjustment $adjustment, CommissionAdjustmentService $service): RedirectResponse
    {
        abort_unless((int) $adjustment->commission_document_id === (int) $document->id, 404);
        $this->authorizeAction($request, 'commissions.review_adjustments');
        $data = $request->validate(['reason' => ['required', 'string', 'max:3000']]);
        $service->review($adjustment, $request->user(), false, $data['reason']);

        return back()->with('success', 'تعدیل رد شد.');
    }

    public function show(Request $request, CommissionSettlement $settlement, CommissionFeatureService $features): View
    {
        $this->authorizeSettlement($request, $settlement, $features);
        $settlement->load(['seller', 'period', 'document', 'payments.creator']);
        $canRecordPayment = $request->user()->hasPermission('commissions.record_payments');
        $canVoidPayment = $request->user()->hasPermission('commissions.void_payments');
        $pilotMode = $features->isPilotMode();

        return view('commercial.commissions.settlements.show', compact('settlement', 'canRecordPayment', 'canVoidPayment', 'pilotMode'));
    }

    public function print(Request $request, CommissionSettlement $settlement, CommissionFeatureService $features): View
    {
        $this->authorizeSettlement($request, $settlement, $features);
        $settlement->load(['seller', 'period', 'document', 'payments.creator']);

        return view('commercial.commissions.settlements.print', compact('settlement'));
    }

    public function recordPayment(Request $request, CommissionSettlement $settlement, CommissionPaymentService $service): RedirectResponse
    {
        $this->authorizeAction($request, 'commissions.record_payments');
        $data = $request->validate(['amount' => ['nullable', 'integer', 'required_without:amount_toman', 'min:1'],
            'amount_toman' => ['nullable', 'string', 'required_without:amount', 'max:30'], 'paid_at' => ['required', 'string'],
            'payment_method' => ['nullable', Rule::in(['bank_transfer', 'cash', 'other'])], 'reference_number' => ['nullable', 'string', 'max:255'],
            'notes' => ['nullable', 'string', 'max:3000'], 'idempotency_key' => ['nullable', 'string', 'max:191']]);
        $data['amount'] = array_key_exists('amount_toman', $data)
            ? Currency::tomanInput($data['amount_toman'])
            : (int) $data['amount'];
        unset($data['amount_toman']);
        if ($data['amount'] <= 0) {
            return back()->withErrors(['amount_toman' => 'مبلغ پرداخت باید بیشتر از صفر باشد.'])->withInput();
        }
        $gregorian = preg_match('/^\d{4}-\d{2}-\d{2}/', $data['paid_at']) ? $data['paid_at'] : JalaliDate::toGregorianDate($data['paid_at']);
        if (! $gregorian) {
            return back()->withErrors(['paid_at' => 'تاریخ پرداخت معتبر نیست.'])->withInput();
        }
        $service->record($settlement, array_merge($data, ['paid_at' => $gregorian]), $request->user());

        return back()->with('success', 'پرداخت پورسانت ثبت شد.');
    }

    public function voidPayment(Request $request, CommissionSettlement $settlement, CommissionPayment $payment, CommissionPaymentService $service): RedirectResponse
    {
        abort_unless((int) $payment->commission_settlement_id === (int) $settlement->id, 404);
        $this->authorizeAction($request, 'commissions.void_payments');
        $data = $request->validate(['reason' => ['required', 'string', 'max:3000']]);
        $service->void($payment, $data['reason'], $request->user());

        return back()->with('success', 'پرداخت با حفظ تاریخچه باطل شد.');
    }

    private function authorizeSettlement(Request $request, CommissionSettlement $settlement, CommissionFeatureService $features): void
    {
        $canViewOwn = $features->isSellerVisibilityEnabled()
            && (int) $request->user()?->id === (int) $settlement->seller_id;

        abort_unless($request->user()?->hasPermission('commissions.view_settlements') || $canViewOwn, 403);
    }

    private function authorizeAction(Request $request, string $permission): void
    {
        abort_unless($request->user()?->hasPermission($permission), 403);
    }
}
