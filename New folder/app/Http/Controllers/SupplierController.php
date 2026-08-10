<?php

namespace App\Http\Controllers;

use App\Models\Supplier;
use Illuminate\Http\Request;

class SupplierController extends Controller
{
    public function index()
    {
        return redirect()->route('persons.index');
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'phone' => ['required', 'string', 'max:20'],
            'province_id' => ['nullable', 'integer'],
            'city_id' => ['nullable', 'integer'],
            'address' => ['nullable', 'string', 'max:500'],
            'postal_code' => ['nullable', 'string', 'max:20'],
            'additional_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $provinces = config('iran.provinces', []);
        $province = collect($provinces)->firstWhere('id', (int) ($data['province_id'] ?? 0));
        $city = collect($province['cities'] ?? [])->firstWhere('id', (int) ($data['city_id'] ?? 0));

        unset($data['province_id'], $data['city_id']);
        $data['province'] = $province['name'] ?? null;
        $data['city'] = $city['name'] ?? null;

        Supplier::create($data);

        return back()->with('success', 'تامین‌کننده جدید اضافه شد.');
    }
}
