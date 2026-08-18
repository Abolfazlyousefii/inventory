<?php

namespace App\Data;

use Carbon\CarbonImmutable;

final readonly class CrmUserData
{
    public function __construct(
        public string $crmUserId,
        public string $name,
        public ?string $phone,
        public ?string $email,
        public ?string $passwordHash,
        public bool $isActive,
        public bool $canAccessErp,
        public bool $isSeller,
        public ?string $username,
        public ?string $personnelCode,
        public ?string $department,
        public ?string $position,
        public ?string $branch,
        public ?string $managerCrmUserId,
        public array $roles,
        public ?CarbonImmutable $createdAt,
        public ?CarbonImmutable $updatedAt,
        public ?string $avatar,
    ) {}
}
