<?php

namespace App\Data;

final readonly class CommissionItemCalculation
{
    public function __construct(public array $ledgerAttributes, public array $audit) {}
}
