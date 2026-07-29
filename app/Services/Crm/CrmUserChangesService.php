<?php

namespace App\Services\Crm;

use App\Services\CrmUserService;

/** @deprecated Use CrmUserService incremental mode. */
final class CrmUserChangesService
{
    public function __construct(private readonly CrmUserService $users) {}

    public function run(): array
    {
        $result = $this->users->syncUsers(full: false);
        return $result + ['ok' => empty($result['error']), 'processed' => $result['received'] ?? 0];
    }
}
