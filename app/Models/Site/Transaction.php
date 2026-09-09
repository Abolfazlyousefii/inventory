<?php

namespace App\Models\Site;

use Bavix\Wallet\Models\Transaction as BaseTransaction;

class Transaction extends BaseTransaction {
    protected $connection = 'site';
}