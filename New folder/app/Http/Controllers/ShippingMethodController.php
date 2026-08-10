<?php

namespace App\Http\Controllers;

use App\Models\ShippingMethod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ShippingMethodController extends Controller
{
    public function index(): View
    {
        $shippingMethods = ShippingMethod::query()->orderBy('name')->paginate(50);

        return view('shipping-methods.index', compact('shippingMethods'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'integer', 'min:0'],
        ]);

        ShippingMethod::create($data);

        return redirect()->route('shipping-methods.index')->with('success', 'روش ارسال با موفقیت ثبت شد.');
    }

    public function update(Request $request, ShippingMethod $shippingMethod): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'price' => ['required', 'integer', 'min:0'],
        ]);

        $shippingMethod->update($data);

        return redirect()->route('shipping-methods.index')->with('success', 'روش ارسال بروزرسانی شد.');
    }

    public function destroy(ShippingMethod $shippingMethod): RedirectResponse
    {
        $shippingMethod->delete();

        return redirect()->route('shipping-methods.index')->with('success', 'روش ارسال حذف شد.');
    }
}
