<?php

namespace App\Http\Controllers\External;

use App\Http\Controllers\Controller;
use App\Models\SiteStockHold;
use App\Services\SiteOrderStockHoldService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SiteStockHoldController extends Controller
{
    public function reserve(Request $request, SiteOrderStockHoldService $service): JsonResponse
    {
        $this->authorizeRequest($request);
        $data = $request->validate([
            'site_order_id' => ['required', 'integer', 'min:1'],
            'expires_in_minutes' => ['required', 'integer', 'min:1', 'max:1440'],
            'lines' => ['required', 'array', 'min:1', 'max:500'],
            'lines.*.variant_id' => ['required', 'integer', 'min:1'],
            'lines.*.quantity' => ['required', 'integer', 'min:1', 'max:100000'],
        ]);
        $hold = $service->reserve((int) $data['site_order_id'], $data['lines'], (int) $data['expires_in_minutes']);
        return $this->result($hold);
    }

    public function show(Request $request, int $siteOrderId): JsonResponse
    {
        $this->authorizeRequest($request);
        $hold = SiteStockHold::query()->where('site_order_id', $siteOrderId)->firstOrFail();
        return $this->result($hold);
    }

    public function confirm(Request $request, int $siteOrderId, SiteOrderStockHoldService $service): JsonResponse
    {
        $this->authorizeRequest($request);
        $data = $request->validate(['hold_id' => ['required', 'integer', 'min:1']]);
        return $this->result($service->confirm($siteOrderId, (int) $data['hold_id']));
    }

    public function release(Request $request, int $siteOrderId, SiteOrderStockHoldService $service): JsonResponse
    {
        $this->authorizeRequest($request);
        $data = $request->validate(['hold_id' => ['required', 'integer', 'min:1']]);
        return $this->result($service->release($siteOrderId, (int) $data['hold_id']));
    }

    private function authorizeRequest(Request $request): void
    {
        $expected = (string) config('services.external_sync.token');
        $provided = (string) ($request->header('X-CRM-Token') ?: $request->bearerToken());
        abort_if($expected === '' || !hash_equals($expected, $provided), 401, 'Unauthenticated.');
    }

    private function result(SiteStockHold $hold): JsonResponse
    {
        return response()->json([
            'hold_id' => $hold->id,
            'site_order_id' => $hold->site_order_id,
            'status' => $hold->status,
            'expires_at' => $hold->expires_at?->toIso8601String(),
        ]);
    }
}
