<?php

namespace App\Http\Controllers;

use App\Models\AssetDocument;
use App\Models\AssetDocumentItem;
use App\Models\AssetDocumentItemCode;
use App\Models\AssetPersonnel;
use App\Models\User;
use App\Services\AssetCodeService;
use App\Services\AssetDocumentService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class AssetDocumentController extends Controller
{
    public function __construct(
        private readonly AssetDocumentService $service,
        private readonly AssetCodeService $codeService,
    ) {}

    public function index(Request $request)
    {
        $status = trim((string) $request->query('status', ''));
        $personnelId = (int) $request->query('personnel_id', 0);
        $documentNo = trim((string) $request->query('document_number', ''));

        $documents = AssetDocument::query()
            ->with(['personnel', 'trusteeUser'])
            ->withCount('items')
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->when($personnelId > 0, fn ($q) => $q->where('personnel_id', $personnelId))
            ->when($documentNo !== '', fn ($q) => $q->where('document_number', 'like', "%{$documentNo}%"))
            ->latest('id')
            ->paginate(20)
            ->withQueryString();

        $personnel = AssetPersonnel::query()->where('is_active', true)->orderBy('full_name')->get(['id', 'full_name']);
        $statusLabels = AssetDocument::statusLabels();

        return view('asset.documents.index', compact('documents', 'personnel', 'status', 'personnelId', 'documentNo', 'statusLabels'));
    }

    public function create()
    {
        $personnel = AssetPersonnel::query()->where('is_active', true)->orderBy('full_name')->get();
        $trusteeUsers = $this->selectableTrusteeUsers();
        $itemNameSuggestions = AssetDocumentItem::query()
            ->select('item_name')
            ->distinct()
            ->orderBy('item_name')
            ->limit(200)
            ->pluck('item_name');

        return view('asset.documents.form', [
            'document' => new AssetDocument([
                'document_date' => now()->toDateString(),
                'status' => AssetDocument::STATUS_DRAFT,
            ]),
            'personnel' => $personnel,
            'trusteeUsers' => $trusteeUsers,
            'itemNameSuggestions' => $itemNameSuggestions,
            'statusLabels' => AssetDocument::statusLabels(),
            'action' => route('asset.documents.store'),
            'method' => 'POST',
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'document_date' => 'required|date',
            'trustee_user_id' => ['required', 'integer', $this->activeUserRule()],
            'description' => 'nullable|string',
            'signed_form' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:5120',
            'items' => 'required|array|min:1',
            'items.*.item_name' => 'required|string|max:255',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.asset_codes_input' => 'required|string',
            'items.*.description' => 'nullable|string',
        ], [
            'signed_form.mimes' => 'فرمت فایل نامه امضاشده باید jpg، jpeg، png، webp یا pdf باشد.',
            'signed_form.max' => 'حجم فایل نامه امضاشده نباید بیشتر از ۵ مگابایت باشد.',
            'items.required' => 'حداقل یک ردیف کالا باید وارد شود.',
            'items.*.item_name.required' => 'نام کالا در همه ردیف‌ها الزامی است.',
            'items.*.quantity.required' => 'تعداد در همه ردیف‌ها الزامی است.',
            'items.*.asset_codes_input.required' => 'ثبت کدهای ۴ رقمی اموال در همه ردیف‌ها الزامی است.',
        ]);

        $user = $this->findActiveUser((int) $data['trustee_user_id']);
        $assetPersonnel = $this->findOrCreateAssetPersonnelForUser($user);
        $data['personnel_id'] = $assetPersonnel->id;
        $data['trustee_user_id'] = $user->id;
        $data['trustee_name_snapshot'] = $user->name;

        $data = array_merge($data, $this->storeSignedForm($request));
        $document = $this->service->create($data, $data['items'], auth()->id());

        return redirect()->route('asset.documents.show', $document)->with('success', 'سند اموال با موفقیت ثبت شد.');
    }

    public function show(AssetDocument $document)
    {
        $document->load(['personnel', 'trusteeUser', 'items.codes', 'creator', 'updater', 'histories.actor']);
        $statusLabels = AssetDocument::statusLabels();
        $isSignedFormImage = $document->signed_form_mime && str_starts_with($document->signed_form_mime, 'image/');

        return view('asset.documents.show', compact('document', 'statusLabels', 'isSignedFormImage'));
    }

    public function view(AssetDocument $document)
    {
        $document->load(['personnel', 'trusteeUser', 'items.codes']);
        $statusLabels = AssetDocument::statusLabels();

        return response()->json([
            'document_number' => $document->document_number,
            'document_date' => optional($document->document_date)->toDateString(),
            'status' => $document->status,
            'status_label' => $statusLabels[$document->status] ?? $document->status,
            'personnel' => $document->personnel,
            'items' => $document->items->map(fn ($item) => [
                'item_name' => $item->item_name,
                'quantity' => (int) $item->quantity,
                'codes' => $item->codes->pluck('asset_code')->values(),
            ]),
        ]);
    }

    public function edit(AssetDocument $document)
    {
        if ($document->status !== AssetDocument::STATUS_DRAFT) {
            return redirect()->route('asset.documents.show', $document)->withErrors(['status' => 'فقط سند پیش‌نویس قابل ویرایش است.']);
        }

        $document->load('items.codes');
        $personnel = AssetPersonnel::query()->where('is_active', true)->orderBy('full_name')->get();
        $trusteeUsers = $this->selectableTrusteeUsers();
        $itemNameSuggestions = AssetDocumentItem::query()
            ->select('item_name')
            ->distinct()
            ->orderBy('item_name')
            ->limit(200)
            ->pluck('item_name');

        return view('asset.documents.form', [
            'document' => $document,
            'personnel' => $personnel,
            'trusteeUsers' => $trusteeUsers,
            'itemNameSuggestions' => $itemNameSuggestions,
            'statusLabels' => AssetDocument::statusLabels(),
            'action' => route('asset.documents.update', $document),
            'method' => 'PUT',
        ]);
    }

    public function update(Request $request, AssetDocument $document)
    {
        $data = $request->validate([
            'document_date' => 'required|date',
            'trustee_user_id' => ['required', 'integer', $this->activeUserRule()],
            'description' => 'nullable|string',
            'signed_form' => 'nullable|file|mimes:jpg,jpeg,png,webp,pdf|max:5120',
            'items' => 'required|array|min:1',
            'items.*.item_name' => 'required|string|max:255',
            'items.*.quantity' => 'required|integer|min:1',
            'items.*.asset_codes_input' => 'required|string',
            'items.*.description' => 'nullable|string',
        ], [
            'signed_form.mimes' => 'فرمت فایل نامه امضاشده باید jpg، jpeg، png، webp یا pdf باشد.',
            'signed_form.max' => 'حجم فایل نامه امضاشده نباید بیشتر از ۵ مگابایت باشد.',
            'items.required' => 'حداقل یک ردیف کالا باید وارد شود.',
            'items.*.item_name.required' => 'نام کالا در همه ردیف‌ها الزامی است.',
            'items.*.quantity.required' => 'تعداد در همه ردیف‌ها الزامی است.',
            'items.*.asset_codes_input.required' => 'ثبت کدهای ۴ رقمی اموال در همه ردیف‌ها الزامی است.',
        ]);

        $user = $this->findActiveUser((int) $data['trustee_user_id']);
        $assetPersonnel = $this->findOrCreateAssetPersonnelForUser($user);
        $data['personnel_id'] = $assetPersonnel->id;
        $data['trustee_user_id'] = $user->id;
        $data['trustee_name_snapshot'] = $user->name;

        $data = array_merge($data, $this->storeSignedForm($request));
        $updated = $this->service->update($document, $data, $data['items'], auth()->id());

        return redirect()->route('asset.documents.show', $updated)->with('success', 'سند اموال بروزرسانی شد.');
    }

    public function print(AssetDocument $document)
    {
        $document->load(['personnel', 'trusteeUser', 'items.codes', 'creator']);

        return view('asset.documents.print', compact('document'));
    }

    public function signedFormView(AssetDocument $document)
    {
        abort_unless($document->signed_form_path, 404);

        return Storage::disk('private')->response(
            $document->signed_form_path,
            $document->signed_form_original_name ?: basename($document->signed_form_path),
            ['Content-Type' => $document->signed_form_mime ?: 'application/octet-stream']
        );
    }

    public function signedFormDownload(AssetDocument $document)
    {
        abort_unless($document->signed_form_path, 404);

        return Storage::disk('private')->download(
            $document->signed_form_path,
            $document->signed_form_original_name ?: basename($document->signed_form_path)
        );
    }

    public function finalize(AssetDocument $document)
    {
        $document = $this->service->finalize($document, auth()->id());

        return back()->with('success', 'سند اموال نهایی شد.');
    }

    public function cancel(AssetDocument $document)
    {
        $document = $this->service->cancel($document, auth()->id());

        return back()->with('success', 'سند اموال لغو شد.');
    }

    public function codeSearchPage(Request $request)
    {
        $code = trim((string) $request->query('code', ''));
        $result = null;

        if ($code !== '') {
            $result = AssetDocumentItemCode::query()
                ->where('asset_code', $code)
                ->with(['item.document.personnel', 'item.document.trusteeUser'])
                ->first();
        }

        return view('asset.codes.search', compact('code', 'result'));
    }

    public function findByCode(string $code)
    {
        if (!preg_match('/^\d{4}$/', $code)) {
            abort(422, 'کد اموال باید دقیقاً 4 رقم باشد.');
        }

        $result = AssetDocumentItemCode::query()
            ->where('asset_code', $code)
            ->with(['item.document.personnel', 'item.document.trusteeUser'])
            ->firstOrFail();

        return response()->json([
            'asset_code' => $result->asset_code,
            'item_name' => $result->item?->item_name,
            'document_number' => $result->item?->document?->document_number,
            'document_status' => $result->item?->document?->status,
            'personnel' => $result->item?->document?->trusteeDisplayName(),
        ]);
    }


    private function selectableTrusteeUsers()
    {
        return User::query()
            ->when(Schema::hasColumn('users', 'is_active'), fn ($q) => $q->where('is_active', true))
            ->orderBy('name')
            ->get(['id', 'name', 'phone', 'email', 'personnel_code', 'department', 'position']);
    }

    private function activeUserRule()
    {
        $rule = Rule::exists('users', 'id');

        if (Schema::hasColumn('users', 'is_active')) {
            $rule->where('is_active', true);
        }

        return $rule;
    }

    private function findActiveUser(int $userId): User
    {
        return User::query()
            ->when(Schema::hasColumn('users', 'is_active'), fn ($q) => $q->where('is_active', true))
            ->findOrFail($userId);
    }

    private function findOrCreateAssetPersonnelForUser(User $user): AssetPersonnel
    {
        $personnel = AssetPersonnel::query()->where('user_id', $user->id)->first();

        if ($personnel) {
            return $personnel;
        }

        $baseCode = trim((string) ($user->personnel_code ?: ('USR-' . $user->id)));
        $personnelCode = $baseCode;

        if (AssetPersonnel::query()->where('personnel_code', $personnelCode)->exists()) {
            $personnelCode = 'USR-' . $user->id;
        }

        $suffix = 1;
        $uniqueCode = $personnelCode;
        while (AssetPersonnel::query()->where('personnel_code', $uniqueCode)->exists()) {
            $uniqueCode = $personnelCode . '-' . $suffix++;
        }

        return AssetPersonnel::query()->create([
            'user_id' => $user->id,
            'user_name_snapshot' => $user->name,
            'full_name' => $user->name,
            'personnel_code' => $uniqueCode,
            'department' => $user->department,
            'position' => $user->position,
            'mobile' => $user->phone,
            'is_active' => true,
            'description' => 'ایجاد خودکار از کاربر سیستم برای امین اموال',
        ]);
    }

    private function storeSignedForm(Request $request): array
    {
        if (!$request->hasFile('signed_form')) {
            return [];
        }

        $file = $request->file('signed_form');
        $path = $file->store('asset-documents/signed-forms', 'private');

        return [
            'signed_form_path' => $path,
            'signed_form_original_name' => $file->getClientOriginalName(),
            'signed_form_mime' => $file->getClientMimeType(),
        ];
    }
}
