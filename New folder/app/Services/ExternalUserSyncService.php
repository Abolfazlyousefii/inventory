<?php

namespace App\Services;

class ExternalUserSyncService
{
    public function __construct(
        private readonly CrmUserService $crmUserService,
    ) {
    }

    public function syncUsers(): array
    {
        return $this->crmUserService->syncUsers();
    }
}

