<?php

use App\Models\Site\User;
use App\Models\Site\Wallet;
use App\Models\Site\WalletHistory;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

beforeEach(function () {
    config([
        'database.connections.site' => [
            'driver' => 'sqlite',
            'database' => ':memory:',
            'prefix' => '',
            'foreign_key_constraints' => true,
        ],
    ]);

    DB::purge('site');

    Schema::connection('site')->create('users', function (Blueprint $table) {
        $table->id();
        $table->timestamps();
    });

    Schema::connection('site')->create('wallets', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('user_id');
        $table->decimal('balance', 18, 0)->default(0);
        $table->boolean('is_active')->default(true);
        $table->timestamps();
    });

    Schema::connection('site')->create('wallet_histories', function (Blueprint $table) {
        $table->id();
        $table->unsignedBigInteger('wallet_id');
        $table->unsignedBigInteger('order_id')->nullable();
        $table->boolean('shipping_cost')->default(false);
        $table->string('type');
        $table->string('source');
        $table->string('status')->default('fail');
        $table->decimal('amount', 18, 0);
        $table->text('description')->nullable();
        $table->timestamps();

        $table->foreign('wallet_id')->references('id')->on('wallets')->cascadeOnDelete();
    });
});

it('uses the site connection and links each site user to the existing custom wallet', function () {
    $user = User::query()->create([]);
    $wallet = Wallet::query()->create([
        'user_id' => $user->id,
        'balance' => 0,
        'is_active' => true,
    ]);

    expect($user->getConnectionName())->toBe('site')
        ->and($wallet->getConnectionName())->toBe('site')
        ->and($user->wallet)->toBeInstanceOf(Wallet::class)
        ->and($user->wallet->is($wallet))->toBeTrue()
        ->and($wallet->user->is($user))->toBeTrue();
});

it('calculates balance from successful deposits and withdrawals only', function () {
    $user = User::query()->create([]);
    $wallet = Wallet::query()->create([
        'user_id' => $user->id,
        'balance' => 0,
        'is_active' => true,
    ]);

    $historyRows = [
        ['type' => 'deposit', 'status' => 'success', 'amount' => 2500],
        ['type' => 'deposit', 'status' => 'fail', 'amount' => 9000],
        ['type' => 'withdraw', 'status' => 'success', 'amount' => 700],
        ['type' => 'withdraw', 'status' => 'fail', 'amount' => 4000],
    ];

    foreach ($historyRows as $row) {
        WalletHistory::query()->create($row + [
            'wallet_id' => $wallet->id,
            'source' => 'admin',
        ]);
    }

    expect((int) $wallet->balance())->toBe(1800)
        ->and($wallet->histories)->toHaveCount(4)
        ->and($wallet->histories->first()->wallet->is($wallet))->toBeTrue();
});

it('refreshes the stored balance without changing wallet history', function () {
    $user = User::query()->create([]);
    $wallet = Wallet::query()->create([
        'user_id' => $user->id,
        'balance' => 123,
        'is_active' => true,
    ]);

    WalletHistory::query()->create([
        'wallet_id' => $wallet->id,
        'type' => 'deposit',
        'source' => 'user',
        'status' => 'success',
        'amount' => 5000,
    ]);

    WalletHistory::query()->create([
        'wallet_id' => $wallet->id,
        'type' => 'withdraw',
        'source' => 'user',
        'status' => 'success',
        'amount' => 1250,
    ]);

    $wallet->refreshBalance();

    expect((int) $wallet->fresh()->getRawOriginal('balance'))->toBe(3750)
        ->and(WalletHistory::query()->where('wallet_id', $wallet->id)->count())->toBe(2);
});

it('does not implement the abandoned Bavix wallet interface', function () {
    $interfaces = class_implements(User::class) ?: [];

    expect(in_array('Bavix\\Wallet\\Interfaces\\Wallet', $interfaces, true))->toBeFalse();
});
