<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Locks the Site order using the Inventory transaction's MySQL connection.
 * This is the same lock ordering as request-driven hold expiration: Site order,
 * then Inventory hold. A separate Site connection lock would NOT be atomic.
 */
class SiteOrderRowLock
{
    public function status(int $siteOrderId): string
    {
        $inventory = DB::connection();
        $site = DB::connection('site');
        if ($inventory->transactionLevel() < 1) {
            throw new RuntimeException('A Site stock transition requires an Inventory transaction.');
        }
        if ($inventory->getDriverName() !== 'mysql' || $site->getDriverName() !== 'mysql') {
            throw new RuntimeException('Cross-schema stock protection requires MySQL.');
        }
        foreach (['host', 'port', 'unix_socket'] as $key) {
            if ((string) config('database.connections.mysql.'.$key)
                !== (string) config('database.connections.site.'.$key)) {
                throw new RuntimeException('Site and Inventory must use the same MySQL server.');
            }
        }
        $schema = (string) $site->getDatabaseName();
        if (! preg_match('/^[A-Za-z0-9_]+$/D', $schema)
            || $schema === $inventory->getDatabaseName()) {
            throw new RuntimeException('Invalid Site schema for stock order locking.');
        }

        $order = $inventory->table($schema.'.orders')
            ->where('id', $siteOrderId)
            ->lockForUpdate()
            ->first(['status']);
        if (! $order) {
            throw ValidationException::withMessages(['hold' => 'Site order does not exist.']);
        }
        return (string) $order->status;
    }
}
