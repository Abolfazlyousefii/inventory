<?php

namespace App\Http\Controllers;

use App\Models\InventoryWebhookLog;
use App\Models\InventoryWebhookSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use App\Services\InventoryWebhookService;

class InventoryWebhookController extends Controller
{
    public function index()
    {
        InventoryWebhookService::processPending();

        if (!Schema::hasTable('inventory_webhook_settings') || !Schema::hasTable('inventory_webhook_logs')) {
            return view('inventory-webhooks.index', [
                'setting' => null,
                'logs' => collect(),
                'dbReady' => false,
            ]);
        }

        $setting = InventoryWebhookSetting::query()->latest('id')->first();
        $logs = InventoryWebhookLog::query()->latest('id')->limit(100)->get();

        return view('inventory-webhooks.index', [
            'setting' => $setting,
            'logs' => $logs,
            'dbReady' => true,
        ]);
    }

    public function update(Request $request)
    {
        if (!Schema::hasTable('inventory_webhook_settings')) {
            return redirect()->route('inventory-webhooks.index')->with('error', 'جدول تنظیمات API هنوز ایجاد نشده است. لطفاً migrate را اجرا کنید.');
        }

        $data = $request->validate([
            'is_enabled' => 'nullable|boolean',
            'endpoint_url' => 'nullable|url|max:2048',
            'secret' => 'nullable|string|max:255',
            'timeout_seconds' => 'required|integer|min:1|max:30',
        ]);

        $setting = InventoryWebhookSetting::query()->latest('id')->first() ?? new InventoryWebhookSetting();
        $setting->fill([
            'is_enabled' => (bool)($data['is_enabled'] ?? false),
            'endpoint_url' => $data['endpoint_url'] ?? null,
            'secret' => $data['secret'] ?? null,
            'timeout_seconds' => $data['timeout_seconds'],
        ])->save();

        return redirect()->route('inventory-webhooks.index')->with('success', 'تنظیمات API با موفقیت ذخیره شد.');
    }
}
