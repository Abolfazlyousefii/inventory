<?php

namespace App\Support;

use App\Models\User;
use App\Models\WarehouseTransfer;
use Illuminate\Http\Request;

final class WarehouseVoucherAccess
{
    public const ROLE = 'Org:warehouse.non_sales_vouchers';

    public const TYPES = ['personnel_asset', 'between_warehouses', 'scrap', 'customer_return'];

    public static function restricted(?User $user): bool
    {
        return $user && ! $user->isAdministrator() && $user->hasRole(self::ROLE);
    }

    public static function deniesRoute(User $user, ?string $route): bool
    {
        return self::restricted($user) && (
            str_starts_with($route ?? '', 'vouchers.sales.')
            || str_starts_with($route ?? '', 'vouchers.sale-delivery.')
            || str_starts_with($route ?? '', 'purchases.')
            || in_array($route, ['vouchers.create', 'vouchers.invoice.products'], true)
        );
    }

    public static function deniesRequest(Request $request): bool
    {
        $user = $request->user();
        if (! self::restricted($user)) {
            return false;
        }
        $route = $request->route()?->getName();
        if (self::deniesRoute($user, $route)) {
            return true;
        }

        if (str_starts_with($route ?? '', 'vouchers.section.')) {
            if (! in_array($request->route('type'), ['personnel', 'transfer', 'scrap', 'return-from-sale'], true)) {
                return true;
            }
        }
        if (in_array($route, ['vouchers.store', 'vouchers.update'], true) && $request->has('voucher_type')) {
            if (! in_array($request->input('voucher_type'), self::TYPES, true)) {
                return true;
            }
        }
        if (in_array($route, ['vouchers.show', 'vouchers.edit', 'vouchers.update', 'vouchers.destroy'], true)) {
            $voucher = $request->route('voucher');
            if (! $voucher instanceof WarehouseTransfer) {
                $voucher = WarehouseTransfer::find($voucher);
            }
            if ($voucher && ! in_array($voucher->voucher_type, self::TYPES, true)) {
                return true;
            }
        }

        return false;
    }
}
