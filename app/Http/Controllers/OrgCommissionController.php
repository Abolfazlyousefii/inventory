<?php

namespace App\Http\Controllers;

use App\Models\OrgCommissionDepartment;
use App\Models\OrgCommissionDocument;
use App\Services\Finance\OrgCommissionService;
use App\Support\JalaliDate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class OrgCommissionController extends Controller
{
    public function __construct(private readonly OrgCommissionService $service) {}

    // ── صفحه اصلی: واحدها + اسناد ─────────────

    public function index(Request $request): View
    {
        $departments = OrgCommissionDepartment::with('activeMembers')->orderBy('sort_order')->get();
        $documents = OrgCommissionDocument::with(['creator:id,name'])
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        return view('finance.org-commission.index', [
            'departments' => $departments,
            'documents' => $documents,
            'activeTab' => $request->string('tab')->toString() === 'documents' ? 'documents' : 'departments',
        ]);
    }

    // ── CRUD واحدها ─────────────────────────────

    public function storeDepartment(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $this->service->createDepartment($data, $request->user()->id);

        return back()->with('success', 'واحد جدید ثبت شد.');
    }

    public function updateDepartment(Request $request, OrgCommissionDepartment $department): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:500'],
        ]);

        $this->service->updateDepartment($department, $data);

        return back()->with('success', 'واحد ویرایش شد.');
    }

    public function toggleDepartment(OrgCommissionDepartment $department): RedirectResponse
    {
        $this->service->toggleDepartment($department);

        return back()->with('success', $department->is_active ? 'واحد غیرفعال شد.' : 'واحد فعال شد.');
    }

    public function syncMembers(Request $request, OrgCommissionDepartment $department): RedirectResponse
    {
        $data = $request->validate([
            'members' => ['required', 'array', 'min:1'],
            'members.*.name' => ['required', 'string', 'max:255'],
            'members.*.role' => ['nullable', 'string', 'max:255'],
        ]);

        $this->service->syncMembers($department, $data['members']);

        return back()->with('success', 'اعضای واحد به‌روزرسانی شد.');
    }

    // ── CRUD سند ────────────────────────────────

    public function create(): View
    {
        return view('finance.org-commission.form', [
            'document' => null,
            'departments' => $this->activeDepartments(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validateDocument($request);

        $document = $this->service->createDocument($data, $request->user());

        return redirect()->route('finance.org-commission.show', $document)
            ->with('success', 'سند پورسانت اداری ثبت شد.');
    }

    public function show(OrgCommissionDocument $document): View
    {
        $document->load([
            'allocations.department',
            'allocations.memberShares.member',
            'creator:id,name',
            'confirmer:id,name',
            'finalizer:id,name',
        ]);

        return view('finance.org-commission.show', compact('document'));
    }

    public function edit(OrgCommissionDocument $document): View
    {
        $document->load(['allocations.department', 'allocations.memberShares.member']);

        return view('finance.org-commission.form', [
            'document' => $document,
            'departments' => $this->activeDepartments(),
        ]);
    }

    public function update(Request $request, OrgCommissionDocument $document): RedirectResponse
    {
        $data = $this->validateDocument($request);

        $document = $this->service->updateDocument($document, $data, $request->user());

        return redirect()->route('finance.org-commission.show', $document)
            ->with('success', 'سند ویرایش شد.');
    }

    public function destroy(OrgCommissionDocument $document): RedirectResponse
    {
        try {
            $this->service->deleteDraft($document);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return redirect()->route('finance.org-commission.index', ['tab' => 'documents'])
            ->with('success', 'سند پیش‌نویس حذف شد.');
    }

    public function confirm(Request $request, OrgCommissionDocument $document): RedirectResponse
    {
        try {
            $this->service->confirmDocument($document, $request->user()->id);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', 'سند تأیید شد.');
    }

    public function finalize(Request $request, OrgCommissionDocument $document): RedirectResponse
    {
        try {
            $this->service->finalizeDocument($document, $request->user()->id);
        } catch (ValidationException $e) {
            return back()->withErrors($e->errors());
        }

        return back()->with('success', 'سند نهایی شد.');
    }

    // AJAX: محاسبه کل پورسانت فروشنده‌ها برای بازه
    public function sellerTotal(Request $request): JsonResponse
    {
        $data = $request->validate([
            'period_from' => ['required', 'string'],
            'period_to' => ['required', 'string'],
        ]);

        $from = $this->normalizeDate($data['period_from']);
        $to = $this->normalizeDate($data['period_to']);

        if (! $from || ! $to || $from > $to) {
            return response()->json(['total' => 0]);
        }

        return response()->json([
            'total' => $this->service->totalSellerCommission($from, $to),
        ]);
    }

    public function print(OrgCommissionDocument $document): View
    {
        $document->load([
            'allocations.department',
            'allocations.memberShares.member',
            'creator:id,name',
            'confirmer:id,name',
            'finalizer:id,name',
        ]);

        return view('finance.org-commission.print', compact('document'));
    }

    private function activeDepartments()
    {
        return OrgCommissionDepartment::where('is_active', true)
            ->with('activeMembers')
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * Jalali dates are normalized before validation so that `date` and
     * `after_or_equal` compare real Gregorian values.
     */
    private function validateDocument(Request $request): array
    {
        $request->merge([
            'period_from' => $this->normalizeDate($request->input('period_from')),
            'period_to' => $this->normalizeDate($request->input('period_to')),
        ]);

        return $request->validate([
            'period_from' => ['required', 'date'],
            'period_to' => ['required', 'date', 'after_or_equal:period_from'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'allocations' => ['nullable', 'array'],
            'allocations.*.department_id' => ['required', 'integer', 'distinct', 'exists:org_commission_departments,id'],
            'allocations.*.percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'allocations.*.members' => ['nullable', 'array'],
            'allocations.*.members.*.member_id' => ['required', 'integer', 'distinct', 'exists:org_commission_department_members,id'],
            'allocations.*.members.*.share_amount' => ['required', 'integer', 'min:0'],
            'allocations.*.members.*.notes' => ['nullable', 'string', 'max:500'],
        ]);
    }

    private function normalizeDate(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        $value = trim((string) $value);

        return preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)
            ? $value
            : JalaliDate::toGregorianDate($value);
    }
}
