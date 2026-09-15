<?php

namespace App\Http\Controllers;

use App\Models\CommissionRateRevision;
use App\Services\Commissions\CommissionRateService;
use App\Services\Commissions\CommissionRateTreeService;
use App\Services\Commissions\CommissionTarget;
use App\Support\JalaliDate;
use App\Support\Percentage;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class CommissionRateSettingsController extends Controller
{
    public function index(CommissionRateTreeService $tree): View
    {
        return view('finance.commission-rates.index', [
            'rootNodes' => $tree->roots(),
        ]);
    }

    public function tree(Request $request, CommissionRateTreeService $tree): JsonResponse
    {
        if ($request->input('scope') === 'all') {
            $data = $request->validate([
                'scope' => ['required', Rule::in(['all'])],
                'q' => ['required', 'string', 'min:2', 'max:100'],
            ]);

            return response()->json($tree->search($data['q']));
        }

        $data = $request->validate([
            'type' => ['required', Rule::in(['category', 'product'])],
            'id' => ['required', 'integer', 'min:1'],
            'q' => ['nullable', 'string', 'max:100'],
            'page' => ['nullable', 'integer', 'min:1'],
        ]);

        return response()->json($tree->children(
            $data['type'],
            (int) $data['id'],
            trim((string) ($data['q'] ?? '')),
            (int) ($data['page'] ?? 1)
        ));
    }

    public function storeRate(Request $request, CommissionRateService $service): RedirectResponse
    {
        $data = $request->validate([
            'target_type' => ['required', Rule::in(['category', 'product', 'variant'])],
            'target_id' => ['required', 'integer', 'min:1'],
            'percentage' => ['required', 'string', 'max:20'],
            'effective_from' => ['nullable', 'string', 'max:20'],
        ]);

        // Rates resolve at the invoice date, so allow a backdated (Jalali) start; blank means now.
        $effectiveFrom = now();
        if (filled($data['effective_from'] ?? null)) {
            $gregorian = JalaliDate::toGregorianDate($data['effective_from']);
            if ($gregorian === null) {
                throw ValidationException::withMessages(['effective_from' => 'تاریخ شروع اعمال نامعتبر است؛ قالب درست: ۱۴۰۵/۰۵/۱۲']);
            }
            $effectiveFrom = Carbon::parse($gregorian)->startOfDay();
        }

        $successMessage = 'نرخ پورسانت '.$data['percentage'].'٪ با حفظ تاریخچه ثبت شد.';

        try {
            $service->setRate(
                $data['target_type'],
                (int) $data['target_id'],
                $data['percentage'],
                $request->user(),
                $effectiveFrom
            );
        } catch (ValidationException $e) {
            // setRate rejects a start on/before the active revision. backdateActiveRate only moves that
            // revision's start and keeps its percentage, so fall back only when the percentages match.
            if (! isset($e->errors()['effective_from']) || ! $this->activeRateHasPercentage($data)) {
                throw $e;
            }

            try {
                $service->backdateActiveRate($data['target_type'], (int) $data['target_id'], $effectiveFrom, $request->user());
            } catch (ValidationException) {
                throw $e;
            }

            $successMessage = 'نرخ پورسانت '.$data['percentage'].'٪ با عقب‌بردن تاریخ مؤثر ثبت شد.';
        }

        $redirectBack = (string) $request->input('redirect_back', '');
        $appRoot = rtrim(url('/'), '/');

        if ($redirectBack !== '' && ($redirectBack === $appRoot || str_starts_with($redirectBack, $appRoot.'/'))) {
            return redirect($redirectBack)->with('success', $successMessage)->with('rate_saved', true);
        }

        return redirect()->route('finance.commission-rates.index')->with('success', $successMessage);
    }

    private function activeRateHasPercentage(array $data): bool
    {
        try {
            $requested = Percentage::normalize($data['percentage']);
        } catch (\Throwable) {
            return false;
        }

        $active = CommissionRateRevision::query()
            ->where('target_key', CommissionTarget::key($data['target_type'], (int) $data['target_id']))
            ->where('active_marker', 1)
            ->first();

        return $active !== null && Percentage::normalize($active->percentage) === $requested;
    }

    public function removeRate(Request $request, CommissionRateService $service): RedirectResponse
    {
        $data = $request->validate([
            'target_type' => ['required', Rule::in(['category', 'product', 'variant'])],
            'target_id' => ['required', 'integer', 'min:1'],
        ]);

        $service->removeRate($data['target_type'], (int) $data['target_id'], $request->user());

        return redirect()->route('finance.commission-rates.index')
            ->with('success', 'نرخ اختصاصی بسته شد؛ نرخ ارث‌بری دوباره اعمال می‌شود.');
    }

    public function rateHistory(Request $request): JsonResponse
    {
        $data = $request->validate([
            'target_type' => ['required', Rule::in(['category', 'product', 'variant'])],
            'target_id' => ['required', 'integer', 'min:1'],
        ]);

        $history = CommissionRateRevision::query()
            ->with('creator:id,name')
            ->where('target_type', $data['target_type'])
            ->where('target_id', $data['target_id'])
            ->latest('effective_from')
            ->get()
            ->map(fn ($rule) => [
                'id' => $rule->id,
                'percentage' => $rule->percentage,
                'effective_from' => JalaliDate::dateTime($rule->effective_from),
                'effective_to' => JalaliDate::dateTime($rule->effective_to, 'فعال'),
                'created_by' => $rule->creator?->name,
            ]);

        return response()->json(['items' => $history]);
    }
}
