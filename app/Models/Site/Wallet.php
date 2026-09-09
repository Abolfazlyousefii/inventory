<?php

namespace App\Models\Site;

use Bavix\Wallet\Models\Wallet as BaseWallet;

class Wallet extends BaseWallet {
    protected $connection = 'site';
}