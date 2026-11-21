<?php

namespace App\Services;

use App\Models\SalesReturnDocument;
use App\Models\Warehouse;
use Illuminate\Validation\ValidationException;

final class SimpleSalesReturnWorkflow
{
    public function normalize(array $data): array
    {
        $warehouse = Warehouse::query()->where('type', 'return')->where('is_active', true)
            ->whereNull('parent_id')->orderBy('id')->first();
        if (! $warehouse) {
            throw ValidationException::withMessages(['document' => 'انبار مرجوعی فعال تعریف نشده است.']);
        }

        $allowed = Warehouse::query()->where('is_active', true)->whereNull('parent_id')
            ->whereIn('type', config('sales_returns.allow_central_destination', true) ? ['central', 'return'] : ['return'])
            ->get()->keyBy('id');
        $hasCentral = false;
        $default = (int) ($data['default_destination_warehouse_id'] ?? $warehouse->id);
        if (! $allowed->has($default)) {
            throw ValidationException::withMessages(['default_destination_warehouse_id' => 'انبار مقصد فعال و مجاز نیست.']);
        }
        $data['default_destination_warehouse_id'] = $default;
        $data['return_reason'] = SalesReturnDocument::FLOW_RETURN_ONLY;
        $data['commission_effect_type'] = null;
        $data['financial_only_reason'] = null;
        $data['description'] = null;
        foreach ($data['items'] ?? [] as $index => $row) {
            $destination = $allowed->get((int) ($row['destination_warehouse_id'] ?? $default));
            if (! $destination) {
                throw ValidationException::withMessages(["items.$index.destination_warehouse_id" => 'انبار مقصد فعال و مجاز نیست.']);
            }
            if ($destination->type === 'central' && ($row['item_condition'] ?? 'healthy') !== 'healthy') {
                throw ValidationException::withMessages(["items.$index.destination_warehouse_id" => 'کالای معیوب فقط به انبار مرجوعی می‌رود.']);
            }
            $hasCentral = $hasCentral || $destination->type === 'central';
            $data['items'][$index]['destination_warehouse_id'] = $destination->id;
        }
        $data['return_flow'] = $hasCentral ? SalesReturnDocument::FLOW_CONTROLLED : SalesReturnDocument::FLOW_RETURN_ONLY;

        return $data;
    }
}
