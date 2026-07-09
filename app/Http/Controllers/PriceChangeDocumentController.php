<?php

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class PriceChangeDocumentController extends Controller
{
    public function index(): View
    {
        return view('products.price-changes.index');
    }

    public function create(): View
    {
        return view('products.price-changes.index');
    }

    public function preview(Request $request): RedirectResponse
    {
        return redirect()->route('products.price-changes.index')
            ->with('status', 'پیش‌نمایش تغییر قیمت کالا هنوز پیاده‌سازی نشده است.');
    }

    public function store(Request $request): RedirectResponse
    {
        return redirect()->route('products.price-changes.index')
            ->with('status', 'ثبت سند تغییر قیمت کالا هنوز پیاده‌سازی نشده است.');
    }

    public function show(string $document): View
    {
        return view('products.price-changes.index', compact('document'));
    }

    public function apply(string $document): RedirectResponse
    {
        return redirect()->route('products.price-changes.index')
            ->with('status', 'اعمال سند تغییر قیمت کالا هنوز پیاده‌سازی نشده است.');
    }

    public function cancel(string $document): RedirectResponse
    {
        return redirect()->route('products.price-changes.index')
            ->with('status', 'لغو سند تغییر قیمت کالا هنوز پیاده‌سازی نشده است.');
    }
}
