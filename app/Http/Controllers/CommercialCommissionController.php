<?php

namespace App\Http\Controllers;

use App\Models\CommissionAdjustment;
use App\Models\CommissionCampaign;
use App\Models\CommissionDocument;
use App\Models\CommissionPeriod;
use App\Models\CommissionRateRevision;
use App\Models\CommissionSetting;
use App\Models\CommissionSettlement;
use App\Models\User;
use App\Services\Commissions\CommissionCalculationService;
use App\Services\Commissions\CommissionCampaignService;
use App\Services\Commissions\CommissionDashboardService;
use App\Services\Commissions\CommissionFeatureService;
use App\Services\Commissions\CommissionPeriodService;
use App\Services\Commissions\CommissionPeriodWorkflowService;
use App\Services\Commissions\CommissionRateService;
use App\Services\Commissions\CommissionRateTreeService;
use App\Services\Commissions\CommissionReportService;
use App\Services\Commissions\CommissionTargetService;
use App\Services\Commissions\CurrentCommissionPeriodResolver;
use App\Support\JalaliDate;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CommercialCommissionController extends Controller
{
    public function index(
        Request $request,
        CommissionRateTreeService $tree,
        CommissionPeriodWorkflowService $workflow,
        CurrentCommissionPeriodResolver $periodResolver,
        CommissionDashboardService $dashboardService,
        CommissionTargetService $targetService,
        CommissionFeatureService $featureService,
    ): View {
        $this->authorizePilotAccess($request, $featureService);
        $currentPeriod = null;
        $periodResolutionFailed = false;
        try {
            $currentPeriod = $periodResolver->resolve();
        } catch (\Throwable $exception) {
            $periodResolutionFailed = true;
            Log::error('Current commission period could not be resolved.', ['exception' => $exception]);
        }

        $campaigns = CommissionCampaign::query()->with(['targets.category:id,name', 'targets.product:id,name', 'targets.variant:id,variant_name,variant_code'])
            ->withCount('targets')->latest('start_at')->get();
        $rootNodes = $tree->roots();
        $setting = CommissionSetting::current();
        $pilotMode = $featureService->isPilotMode();
        $sellerVisibilityEnabled = $featureService->isSellerVisibilityEnabled();
        $targetsEnabled = $featureService->areTargetsEnabled();
        $periods = CommissionPeriod::query()->latest('start_at')->get();
        $period = $request->integer('period')
            ? $periods->firstWhere('id', $request->integer('period'))
            : $currentPeriod ?? $periods->first();
        $permissions = [
            'rates' => auth()->user()->hasPermission('commissions.manage_rates'),
            'campaigns' => auth()->user()->hasPermission('commissions.manage_campaigns'),
            'periods' => auth()->user()->hasPermission('commissions.manage_periods'),
            'targets' => auth()->user()->hasPermission('commissions.manage_targets'),
            'recalculate' => auth()->user()->hasPermission('commissions.recalculate'),
            'seller_details' => auth()->user()->hasPermission('commissions.view_seller_details'),
        ];
        $canViewTeam = ! $request->user()->is_seller || $this->canManageCommissions($request->user());
        $dashboard = $period ? $dashboardService->build($period, $canViewTeam ? null : (int) $request->user()->id, $targetsEnabled) : null;
        $summary = $dashboard['totals'] ?? null;
        $sellerSummaries = collect($dashboard['seller_rows'] ?? []);
        $documentsQuery = CommissionDocument::query()->with(['seller:id,name', 'period:id,label', 'settlement:id,commission_document_id,status'])
            ->withCount(['items', 'items as pending_count' => fn ($query) => $query->where('status', 'pending'),
                'items as approved_count' => fn ($query) => $query->where('status', 'approved'),
                'items as rejected_count' => fn ($query) => $query->where('status', 'rejected')])
            ->withSum(['items as approved_commission' => fn ($query) => $query->where('status', 'approved')], 'total_commission_snapshot')
            ->when($period, fn ($query) => $query->where('commission_period_id', $period->id))
            ->when(! $canViewTeam, fn ($query) => $query->where('seller_id', $request->user()->id));
        $documentStats = [
            'total' => (clone $documentsQuery)->count(),
            'pending' => (clone $documentsQuery)->whereHas('items', fn ($query) => $query->where('status', 'pending'))->count(),
            'finalized' => (clone $documentsQuery)->where('status', CommissionDocument::STATUS_FINALIZED)->count(),
            'settled' => (clone $documentsQuery)->whereHas('settlement', fn ($query) => $query->where('status', 'paid'))->count(),
        ];
        $documents = $documentsQuery->latest()->paginate(15, ['*'], 'documents_page');
        $documentSellers = $canViewTeam
            ? User::query()->activeSellers()->orderBy('name')->get(['id', 'name'])
            : User::query()->whereKey($request->user()->id)->where('is_seller', true)->get(['id', 'name']);
        $permissions['manage_documents'] = auth()->user()->hasPermission('commissions.manage_documents');
        $permissions['print_documents'] = auth()->user()->hasPermission('commissions.print_documents');
        $permissions['close_periods'] = auth()->user()->hasPermission('commissions.close_periods');
        $permissions['manage_adjustments'] = auth()->user()->hasPermission('commissions.manage_adjustments');
        $permissions['view_settlements'] = auth()->user()->hasPermission('commissions.view_settlements');
        $permissions['record_payments'] = auth()->user()->hasPermission('commissions.record_payments');
        $permissions['mark_paid'] = auth()->user()->hasPermission('commissions.mark_period_paid');
        $adjustments = CommissionAdjustment::query()->with(['seller:id,name', 'period:id,label'])
            ->when($period, fn ($query) => $query->where('commission_period_id', $period->id))
            ->when(! $canViewTeam, fn ($query) => $query->where('seller_id', $request->user()->id))
            ->latest()->paginate(15, ['*'], 'adjustments_page');
        $settlements = CommissionSettlement::query()->with(['seller:id,name', 'period:id,label'])
            ->when($period, fn ($query) => $query->where('commission_period_id', $period->id))
            ->when(! $canViewTeam, fn ($query) => $query->where('seller_id', $request->user()->id))
            ->latest()->paginate(15, ['*'], 'settlements_page');
        $targetRows = $period && $targetsEnabled && $permissions['targets'] ? $targetService->managementRows($period) : collect();
        $reviewBlockers = $period && $period->status === CommissionPeriod::STATUS_OPEN ? $workflow->reviewBlockers($period) : [];
        $closeBlockers = $period && $period->status === CommissionPeriod::STATUS_REVIEW ? $workflow->closeBlockers($period) : [];
        $paidBlockers = $period && $period->status === CommissionPeriod::STATUS_CLOSED ? $workflow->paidBlockers($period) : [];

        return view('commercial.commissions.index', compact('rootNodes', 'campaigns', 'setting', 'periods', 'period', 'permissions', 'summary', 'sellerSummaries', 'documents', 'documentStats', 'documentSellers', 'adjustments', 'settlements', 'reviewBlockers', 'closeBlockers', 'paidBlockers', 'dashboard', 'targetRows', 'periodResolutionFailed', 'canViewTeam', 'pilotMode', 'sellerVisibilityEnabled', 'targetsEnabled'));
    }

    public function tree(Request $request, CommissionRateTreeService $tree, CommissionFeatureService $features): JsonResponse
    {
        $this->authorizePilotAccess($request, $features);
        if ($request->input('scope') === 'all') {
            $data = $request->validate([
                'scope' => ['required', Rule::in(['all'])],
                'q' => ['required', 'string', 'min:2', 'max:100'],
            ]);

            return response()->json($tree->search($data['q']));
        }

        $data = $request->validate(['type' => ['required', Rule::in(['category', 'product'])], 'id' => ['required', 'integer', 'min:1'], 'q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);

        return response()->json($tree->children($data['type'], (int) $data['id'], trim((string) ($data['q'] ?? '')), (int) ($data['page'] ?? 1)));
    }

    public function storeRate(Request $request, CommissionRateService $service): RedirectResponse
    {
        $this->authorizeAction($request, 'commissions.manage_rates');
        $request->mergeIfMissing(['effective_mode' => 'today']);
        $data = $request->validate([
            'target_type' => ['required', Rule::in(['category', 'product', 'variant'])],
            'target_id' => ['required', 'integer', 'min:1'],
            'percentage' => ['required', 'string', 'max:20'],
            'effective_mode' => ['required', Rule::in(['period_start', 'today', 'custom'])],
            'period_id' => ['nullable', 'integer', 'exists:commission_periods,id'],
            'effective_from' => ['nullable', 'string', 'max:30'],
        ]);
        $effectiveFrom = match ($data['effective_mode']) {
            'period_start' => $this->mutablePeriodStart((int) ($data['period_id'] ?? 0)),
            'custom' => $this->customEffectiveFrom($data['effective_from'] ?? null),
            default => now(),
        };
        $service->setRate($data['target_type'], (int) $data['target_id'], $data['percentage'], $request->user(), $effectiveFrom);

        return redirect()->route('commercial.commissions.index')->with('success', 'نرخ پورسانت با حفظ تاریخچه ثبت شد.');
    }

    public function removeRate(Request $request, CommissionRateService $service): RedirectResponse
    {
        $this->authorizeAction($request, 'commissions.manage_rates');
        $data = $request->validate(['target_type' => ['required', Rule::in(['category', 'product', 'variant'])], 'target_id' => ['required', 'integer', 'min:1']]);
        $service->removeRate($data['target_type'], (int) $data['target_id'], $request->user());

        return redirect()->route('commercial.commissions.index')->with('success', 'نرخ اختصاصی بسته شد؛ نرخ ارث‌بری دوباره اعمال می‌شود.');
    }

    public function rateHistory(Request $request, CommissionFeatureService $features): JsonResponse
    {
        $this->authorizePilotAccess($request, $features);
        $data = $request->validate(['target_type' => ['required', Rule::in(['category', 'product', 'variant'])], 'target_id' => ['required', 'integer', 'min:1']]);
        $history = CommissionRateRevision::query()->with('creator:id,name')->where('target_type', $data['target_type'])->where('target_id', $data['target_id'])->latest('effective_from')->get()->map(fn ($rule) => ['id' => $rule->id, 'percentage' => $rule->percentage, 'effective_from' => JalaliDate::dateTime($rule->effective_from), 'effective_to' => JalaliDate::dateTime($rule->effective_to, 'فعال'), 'created_by' => $rule->creator?->name]);

        return response()->json(['items' => $history]);
    }

    public function storeCampaign(Request $request, CommissionCampaignService $service): RedirectResponse
    {
        $this->authorizeAction($request, 'commissions.manage_campaigns');
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'bonus_percentage' => ['required', 'string', 'max:20'], 'start_date' => ['required', 'string'], 'end_date' => ['required', 'string'], 'notes' => ['nullable', 'string', 'max:3000'], 'targets' => ['required', 'array', 'min:1'], 'targets.*' => ['required', 'string', 'max:64']]);
        $start = JalaliDate::toGregorianDate($data['start_date']);
        $end = JalaliDate::toGregorianDate($data['end_date']);
        if (! $start || ! $end) {
            return back()->withInput()->withErrors(['start_date' => 'تاریخ شمسی واردشده معتبر نیست.']);
        }
        $service->save(array_merge($data, ['start_at' => $start.' 00:00:00', 'end_at' => Carbon::parse($end)->addDay()->format('Y-m-d').' 00:00:00']), $request->user());

        return redirect()->route('commercial.commissions.index')->with('success', 'کمپین پورسانت ثبت شد.');
    }

    public function updateCampaign(Request $request, CommissionCampaign $campaign, CommissionCampaignService $service): RedirectResponse
    {
        $this->authorizeAction($request, 'commissions.manage_campaigns');
        $data = $request->validate(['name' => ['required', 'string', 'max:255'], 'bonus_percentage' => ['required', 'string', 'max:20'], 'start_date' => ['required', 'string'], 'end_date' => ['required', 'string'], 'notes' => ['nullable', 'string', 'max:3000'], 'targets' => ['required', 'array', 'min:1'], 'targets.*' => ['required', 'string', 'max:64']]);
        $start = JalaliDate::toGregorianDate($data['start_date']);
        $end = JalaliDate::toGregorianDate($data['end_date']);
        if (! $start || ! $end) {
            return back()->withInput()->withErrors(['start_date' => 'تاریخ شمسی واردشده معتبر نیست.']);
        }
        $service->save(array_merge($data, ['start_at' => $start.' 00:00:00', 'end_at' => Carbon::parse($end)->addDay()->format('Y-m-d').' 00:00:00']), $request->user(), $campaign);

        return redirect()->route('commercial.commissions.index')->with('success', 'کمپین با حفظ سابقه زمانی ویرایش شد.');
    }

    public function archiveCampaign(Request $request, CommissionCampaign $campaign, CommissionCampaignService $service): RedirectResponse
    {
        $this->authorizeAction($request, 'commissions.manage_campaigns');
        $service->archive($campaign, $request->user());

        return redirect()->route('commercial.commissions.index')->with('success', 'کمپین بایگانی شد و تاریخچه آن حفظ شد.');
    }

    public function updateSetting(Request $request, CommissionPeriodService $service): RedirectResponse
    {
        $this->authorizeAction($request, 'commissions.manage_periods');
        $service->updateCycleDay($request->input('cycle_day'), $request->user());

        return redirect()->route('commercial.commissions.index')->with('success', 'روز چرخه برای دوره‌های آینده به‌روزرسانی شد.');
    }

    public function updateFeatureSettings(Request $request, CommissionFeatureService $features): RedirectResponse
    {
        $this->authorizeAction($request, 'commissions.manage_periods');
        $data = $request->validate([
            'pilot_mode' => ['required', 'boolean'],
            'seller_visibility_enabled' => ['required', 'boolean'],
            'targets_enabled' => ['required', 'boolean'],
        ]);
        $features->update($data, $request->user());

        return redirect()->route('commercial.commissions.index')->with('success', 'تنظیمات آزمایشی پورسانت به‌روزرسانی شد.');
    }

    public function createPeriod(Request $request, CommissionPeriodService $service): RedirectResponse
    {
        $this->authorizeAction($request, 'commissions.manage_periods');
        $service->createForDate(now(), CommissionSetting::current()->cycle_day);

        return redirect()->route('commercial.commissions.index')->with('success', 'دوره جاری با مرزهای ثابت ایجاد شد.');
    }

    public function updateTarget(Request $request, CommissionPeriod $period, User $seller, CommissionTargetService $service): RedirectResponse
    {
        $this->authorizeAction($request, 'commissions.manage_targets');
        $data = $request->validate([
            'target_amount' => ['required', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:3000'],
        ]);
        $service->save($seller, $period, $data['target_amount'], $request->user(), $data['notes'] ?? null);

        return redirect()->route('commercial.commissions.index', ['period' => $period->id])
            ->with('success', 'تارگت پورسانت با موفقیت ذخیره شد.');
    }

    public function copyPreviousTargets(Request $request, CommissionPeriod $period, CommissionTargetService $service): RedirectResponse
    {
        $this->authorizeAction($request, 'commissions.manage_targets');
        $result = $service->copyPrevious($period, $request->user());

        return redirect()->route('commercial.commissions.index', ['period' => $period->id])->with(
            'success',
            number_format($result['copied']).' تارگت کپی شد، '.number_format($result['existing']).' فروشنده از قبل تارگت داشت و '.number_format($result['without_previous']).' فروشنده تارگت دوره قبل نداشت.'
        );
    }

    public function recalculate(Request $request, CommissionPeriod $period, CommissionCalculationService $service): RedirectResponse
    {
        $this->authorizeAction($request, 'commissions.recalculate');
        $service->recalculate($period);

        return redirect()->route('commercial.commissions.index', ['period' => $period->id])->with('success', 'محاسبات تخمینی دوره به‌روزرسانی شد.');
    }

    public function sellerDetails(Request $request, CommissionPeriod $period, User $seller, CommissionReportService $reports, CommissionFeatureService $features): View
    {
        $canViewAll = $request->user()->hasPermission('commissions.view_seller_details');
        $canViewOwn = $features->isSellerVisibilityEnabled() && (int) $request->user()->id === (int) $seller->id;
        abort_unless($canViewAll || $canViewOwn, 403);

        return view('commercial.commissions.seller-details', ['period' => $period, 'seller' => $seller,
            'entries' => $reports->sellerDetails($period, $seller),
            'returns' => $reports->sellerCorrections($period, $seller, 'returns'),
            'reassignments' => $reports->sellerCorrections($period, $seller, 'reassignments')]);
    }

    private function mutablePeriodStart(int $periodId): Carbon
    {
        $period = CommissionPeriod::query()->find($periodId);
        if (! $period || ! in_array($period->status, [CommissionPeriod::STATUS_OPEN, CommissionPeriod::STATUS_REVIEW], true)) {
            throw ValidationException::withMessages(['period_id' => 'فقط دوره باز یا در حال بررسی قابل انتخاب است.']);
        }

        return Carbon::parse($period->start_at);
    }

    private function customEffectiveFrom(?string $value): Carbon
    {
        $gregorian = $value ? JalaliDate::toGregorianDate($value) : null;
        if (! $gregorian) {
            throw ValidationException::withMessages(['effective_from' => 'تاریخ مؤثر واردشده معتبر نیست.']);
        }

        return Carbon::parse($gregorian)->startOfDay();
    }

    private function authorizeAction(Request $request, string $permission): void
    {
        abort_unless($request->user()?->hasPermission($permission), 403);
    }

    private function authorizePilotAccess(Request $request, CommissionFeatureService $features): void
    {
        $user = $request->user();
        if (! $user?->is_seller || $this->canManageCommissions($user)) {
            return;
        }

        abort_unless($features->isSellerVisibilityEnabled(), 403);
    }

    private function canManageCommissions(User $user): bool
    {
        return collect([
            'commissions.manage_rates',
            'commissions.manage_campaigns',
            'commissions.manage_periods',
            'commissions.manage_targets',
            'commissions.recalculate',
            'commissions.view_seller_details',
            'commissions.manage_documents',
            'commissions.review_documents',
            'commissions.finalize_documents',
            'commissions.print_documents',
            'commissions.close_periods',
            'commissions.manage_adjustments',
            'commissions.review_adjustments',
            'commissions.record_payments',
            'commissions.void_payments',
            'commissions.mark_period_paid',
            'commissions.view_settlements',
        ])->contains(fn (string $permission) => $user->hasPermission($permission));
    }
}
