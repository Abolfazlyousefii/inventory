<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Services\{CustomerOwnership, CustomerSaleAuthorization};
use App\Support\ProductFinderSearchNormalizer;
use Illuminate\Http\Request;

class CustomerSaleApprovalController extends Controller
{
    public function __construct(private readonly CustomerSaleAuthorization $authorization, private readonly CustomerOwnership $ownership) {}

    public function lookup(Request $request)
    {
        $this->authorization->authorizeRequester($request->user());
        $request->validate(['code' => 'required|string|max:12']);
        $request->merge(['code' => ProductFinderSearchNormalizer::normalize((string) $request->input('code'))]);
        $data = $request->validate(['code' => ['required', 'regex:/^\d{1,12}$/']]);
        $customer = Customer::where('is_active', true)->where('crm_customer_id', $data['code'])->first()
            ?? Customer::where('is_active', true)->find((int) $data['code']);
        abort_unless($customer, 404, 'مشتری فعال با این کد پیدا نشد.');
        $assignment = $customer->assignment()->with('seller:id,name,is_active,can_access_erp')->first();
        $requires = !$this->ownership->canSellAny($request->user()) && $assignment?->seller_id !== $request->user()->id;
        return response()->json([
            'customer' => ['id' => $customer->id, 'code' => $customer->crm_customer_id ?: $customer->id, 'name' => $customer->display_name],
            'owner_name' => $assignment?->seller?->name, 'requires_approval' => $requires,
            'can_request' => $requires && $assignment?->seller?->is_active && $assignment?->seller?->can_access_erp,
        ])->header('Cache-Control', 'private, no-store');
    }

    public function issue(Request $request)
    {
        $this->authorization->authorizeRequester($request->user());
        $data = $request->validate(['customer_id' => 'required|integer', 'context' => 'required|uuid']);
        $approval = $this->authorization->issue(Customer::findOrFail($data['customer_id']), $request->user(), $data['context']);
        return response()->json(['id' => $approval->id, 'expires_in' => 300, 'retry_after' => 60, 'message' => config('sms.default') === 'log' ? 'حالت آزمایشی لوکال؛ ارسال پیامک شبیه‌سازی شد و کد در صفحه نمایش داده نمی‌شود.' : 'کد تأیید برای مسئول مشتری ارسال شد.'])->header('Cache-Control', 'private, no-store');
    }

    public function verify(Request $request, string $approval)
    {
        // Validation failures must never flash the approval code into session input.
        $request->headers->set('Accept', 'application/json');
        $this->authorization->authorizeRequester($request->user());
        $request->validate(['code' => 'required|string|max:6']);
        $code = ProductFinderSearchNormalizer::normalize((string) $request->input('code'));
        $request->merge(['code' => $code]);
        $data = $request->validate(['context' => 'required|uuid', 'code' => ['required', 'regex:/^\d{4}$/']]);
        $this->authorization->verify($approval, $request->user(), $data['context'], $data['code']);
        return response()->json(['ok' => true, 'message' => 'مجوز ثبت همین سند تأیید شد؛ مشتری منتقل نمی‌شود.'])->header('Cache-Control', 'private, no-store');
    }
}
