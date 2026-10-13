<?php

namespace App\Actions\UserAuth;

use App\Contracts\OtpSender;
use App\Models\User;
use App\Models\UserLoginCode;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/** Shared OTP issuance for the ERP login form and the local diagnostic command. */
class IssueUserOtpAction
{
    public function execute(string $phone, OtpSender $sender): bool
    {
        $user = User::query()->activeErpUsers()->where('phone', $phone)->first();

        if ($user === null) {
            return false;
        }

        $code = (string) random_int(100000, 999999);

        // Retain any existing valid code if SMS delivery is rejected.
        // The default terminal preview sender captures the code instead of delivering it.
        $sender->send($phone, $code);

        DB::transaction(function () use ($user, $phone, $code): void {
            UserLoginCode::query()
                ->where('user_id', $user->id)
                ->whereNull('consumed_at')
                ->update(['consumed_at' => now()]);

            UserLoginCode::query()->create([
                'user_id' => $user->id,
                'phone' => $phone,
                'code_hash' => Hash::make($code),
                'expires_at' => now()->addMinutes((int) config('jahesh.otp.expires_minutes', 5)),
            ]);
        });

        return true;
    }
}
