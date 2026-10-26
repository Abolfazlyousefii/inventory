<?php

namespace App\Support;

use App\Models\User;

/** A local read-only constraint overrides broad inherited finance permissions. */
final class AccountStatementAccess
{
    public const ROLE = 'Org:finance.account_statements_readonly';

    private const WRITE_ROUTES = [
        'account-statements.payments.store', 'account-statements.payments.edit',
        'account-statements.payments.update', 'account-statements.payments.destroy',
        'account-statements.adjustments.store', 'invoices.payments.store', 'cheques.store',
    ];

    private const WRITE_PERMISSIONS = [
        'account_statements.adjust', 'account_statements.payments.create',
        'payments.create', 'payments.edit', 'payments.delete', 'cheques.create',
    ];

    public static function readOnly(User $user): bool
    {
        return ! $user->isAdministrator() && $user->hasRole(self::ROLE);
    }

    public static function deniesRoute(User $user, ?string $route): bool
    {
        return in_array($route, self::WRITE_ROUTES, true) && self::readOnly($user);
    }

    public static function deniesPermission(User $user, string $key): bool
    {
        return in_array($key, self::WRITE_PERMISSIONS, true) && self::readOnly($user);
    }
}
