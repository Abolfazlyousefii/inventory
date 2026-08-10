<?php

namespace App\Http\Controllers;

use App\Models\Color;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class ColorController extends Controller
{
    public function index()
    {
        // اگر هنوز رنگی تعریف نشده باشد، ۳۲ رنگ پیش‌فرض را یک‌بار ایجاد می‌کنیم
        if (Color::query()->count() === 0) {
            $this->insertDefaultColors();
        }

        $colors = Color::query()->orderBy('code')->paginate(100);

        return view('colors.index', compact('colors'));
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'code' => ['required', 'digits:2', 'unique:colors,code'],
            'hex_code' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        Color::create([
            'name' => $data['name'],
            'code' => $data['code'],
            'hex_code' => strtoupper($data['hex_code']),
        ]);

        return back()->with('success', 'رنگ جدید ثبت شد.');
    }

    public function update(Request $request, Color $color): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'code' => ['required', 'digits:2', 'unique:colors,code,' . $color->id],
            'hex_code' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        $color->update([
            'name' => $data['name'],
            'code' => $data['code'],
            'hex_code' => strtoupper($data['hex_code']),
        ]);

        return back()->with('success', 'رنگ بروزرسانی شد.');
    }

    public function destroy(Color $color): RedirectResponse
    {
        $color->delete();

        return back()->with('success', 'رنگ حذف شد.');
    }

    public function seedDefaults(): RedirectResponse
    {
        $inserted = $this->insertDefaultColors();

        return back()->with('success', "{$inserted} رنگ پیش‌فرض بارگذاری شد.");
    }

    private function insertDefaultColors(): int
    {
        $inserted = 0;

        foreach ($this->defaultColors() as $idx => $row) {
            $code = str_pad((string) ($idx + 1), 2, '0', STR_PAD_LEFT);

            if (Color::query()->where('code', $code)->exists()) {
                continue;
            }

            Color::create([
                'name' => $row['name'],
                'code' => $code,
                'hex_code' => $row['hex_code'],
            ]);

            $inserted++;
        }

        return $inserted;
    }

    private function defaultColors(): array
    {
        return [
            ['name' => 'مشکی', 'hex_code' => '#000000'],
            ['name' => 'سفید', 'hex_code' => '#FFFFFF'],
            ['name' => 'نقره‌ای', 'hex_code' => '#C0C0C0'],
            ['name' => 'خاکستری', 'hex_code' => '#808080'],
            ['name' => 'طلایی', 'hex_code' => '#D4AF37'],
            ['name' => 'رزگلد', 'hex_code' => '#B76E79'],
            ['name' => 'سرمه‌ای', 'hex_code' => '#1A237E'],
            ['name' => 'آبی', 'hex_code' => '#1E88E5'],
            ['name' => 'آبی روشن', 'hex_code' => '#81D4FA'],
            ['name' => 'سبز', 'hex_code' => '#2E7D32'],
            ['name' => 'سبز روشن', 'hex_code' => '#8BC34A'],
            ['name' => 'زرد', 'hex_code' => '#FDD835'],
            ['name' => 'نارنجی', 'hex_code' => '#FB8C00'],
            ['name' => 'قرمز', 'hex_code' => '#E53935'],
            ['name' => 'زرشکی', 'hex_code' => '#800020'],
            ['name' => 'بنفش', 'hex_code' => '#8E24AA'],
            ['name' => 'یاسی', 'hex_code' => '#CE93D8'],
            ['name' => 'صورتی', 'hex_code' => '#F48FB1'],
            ['name' => 'قهوه‌ای', 'hex_code' => '#6D4C41'],
            ['name' => 'نسکافه‌ای', 'hex_code' => '#A1887F'],
            ['name' => 'کرم', 'hex_code' => '#FFF3E0'],
            ['name' => 'فیروزه‌ای', 'hex_code' => '#26C6DA'],
            ['name' => 'لیمویی', 'hex_code' => '#DCE775'],
            ['name' => 'زیتونی', 'hex_code' => '#7CB342'],
            ['name' => 'ذغالی', 'hex_code' => '#37474F'],
            ['name' => 'مسی', 'hex_code' => '#B87333'],
            ['name' => 'بژ', 'hex_code' => '#D7CCC8'],
            ['name' => 'استخوانی', 'hex_code' => '#F5F5DC'],
            ['name' => 'شفاف', 'hex_code' => '#E5E7EB'],
            ['name' => 'چند رنگ', 'hex_code' => '#A78BFA'],
            ['name' => 'طرح‌دار', 'hex_code' => '#F59E0B'],
            ['name' => 'نامشخص', 'hex_code' => '#9CA3AF'],
        ];
    }
}
