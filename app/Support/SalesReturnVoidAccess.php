<?php

namespace App\Support;

use App\Models\User;

/**
 * Authorization for voiding an applied sales return.
 *
 * A void grant is distinct from edit access. Returns with central warehouse
 * reception or stock history are immutable even for warehouse managers.
 * Keep this rule shared by list/detail actions and the POST endpoint.
 */
final class SalesReturnVoidAccess
{
    public static function allowed(?User $user, bool $requiresWarehouse): bool
    {
        if ($requiresWarehouse || ! $user || ! PageAccessCatalog::userCanRoute($user, 'vouchers.return-from-sale.applied.void')) {
            return false;
        }

        return PageAccessCatalog::userCanRoute($user, 'vouchers.return-from-sale.index');
    }
}
