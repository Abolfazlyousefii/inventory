<?php

namespace App\Models\Site;

use Bavix\Wallet\Models\Transfer as BaseTransfer;

class Transfer extends BaseTransfer {
    protected $connection = 'site';
}