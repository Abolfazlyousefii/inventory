<?php

namespace App\Http\Controllers;

use App\Models\AssetPersonnel;
use App\Services\AssetPersonnelService;
use Illuminate\Http\Request;

class AssetPersonnelController extends Controller
{
    public function __construct(private readonly AssetPersonnelService $service) {}

    public function index(Request $request)
    {
        $q = trim((string) $request->query('q', ''));

        $personnel = AssetPersonnel::query()
            ->when($q !== '', function ($query) use ($q) {
                $query->where('full_name', 'like', "%{$q}%")
                    ->orWhere('personnel_code', 'like', "%{$q}%");
            })
            ->orderByDesc('id')
            ->paginate(20)
            ->withQueryString();

        return view('asset.personnel.index', compact('personnel', 'q'));
    }

    public function create()
    {
        return view('asset.personnel.form', ['personnel' => new AssetPersonnel()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'full_name' => 'required|string|max:255',
            'personnel_code' => 'required|string|max:100|unique:asset_personnel,personnel_code',
            'national_code' => 'nullable|string|max:20',
            'department' => 'nullable|string|max:255',
            'position' => 'nullable|string|max:255',
            'mobile' => 'nullable|string|max:30',
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        $data['is_active'] = (bool) ($data['is_active'] ?? true);
        $personnel = $this->service->create($data);

        return redirect()->route('asset.personnel.index')->with('success', 'پرسنل با موفقیت ثبت شد.');
    }

    public function show(AssetPersonnel $personnel)
    {
        $personnel->loadCount('documents');

        return view('asset.personnel.show', compact('personnel'));
    }

    public function edit(AssetPersonnel $personnel)
    {
        return view('asset.personnel.form', compact('personnel'));
    }

    public function update(Request $request, AssetPersonnel $personnel)
    {
        $data = $request->validate([
            'full_name' => 'required|string|max:255',
            'personnel_code' => 'required|string|max:100|unique:asset_personnel,personnel_code,' . $personnel->id,
            'national_code' => 'nullable|string|max:20',
            'department' => 'nullable|string|max:255',
            'position' => 'nullable|string|max:255',
            'mobile' => 'nullable|string|max:30',
            'description' => 'nullable|string',
            'is_active' => 'nullable|boolean',
        ]);

        $data['is_active'] = (bool) ($data['is_active'] ?? false);
        $this->service->update($personnel, $data);

        return redirect()->route('asset.personnel.index')->with('success', 'اطلاعات پرسنل بروزرسانی شد.');
    }

    public function toggleStatus(AssetPersonnel $personnel)
    {
        $personnel = $this->service->toggleStatus($personnel);

        return back()->with('success', 'وضعیت پرسنل به ' . ($personnel->is_active ? 'فعال' : 'غیرفعال') . ' تغییر کرد.');
    }
}
