<?php

namespace App\Models\Site;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Wallet extends Model
{
    protected $connection = 'site';

    protected $table = 'wallets';

    protected $guarded = ['id'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'id');
    }

    public function histories(): HasMany
    {
        return $this->hasMany(WalletHistory::class, 'wallet_id', 'id');
    }

    /**
     * Calculate the available balance from successful wallet history rows.
     * Failed history rows intentionally do not affect the balance.
     */
    public function balance()
    {
        $deposits = $this->histories()
            ->where('status', 'success')
            ->where('type', 'deposit')
            ->sum('amount');

        $withdrawals = $this->histories()
            ->where('status', 'success')
            ->where('type', 'withdraw')
            ->sum('amount');

        return $deposits - $withdrawals;
    }

    public function refreshBalance(): void
    {
        $this->balance = $this->balance();
        $this->save();
    }

    /**
     * Backwards-compatible alias for the original method name.
     *
     * @deprecated Use refreshBalance().
     */
    public function refereshBalance(): void
    {
        $this->refreshBalance();
    }
}
