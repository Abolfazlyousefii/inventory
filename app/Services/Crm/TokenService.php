<?php

namespace App\Services\Crm;

use Illuminate\Support\Carbon;

class TokenService {
    const TIME_LIFE = '9';

    public function hash( string $string ): array {
        $dateTime = Carbon::now()
            ->format('Y-m-d H:i');

        $token = $this->makeHash($string, $dateTime);

        return [
            'string' => $string,
            'token' => $token,
        ];
    }

    public function verify( string $string, string $token ): bool {
        $now = Carbon::now();

        for ( $minute = - self::TIME_LIFE; $minute <= self::TIME_LIFE; $minute ++ ) {
            $dateTime = $now->copy()
                ->addMinutes($minute)
                ->format('Y-m-d H:i');

            $generatedToken = $this->makeHash($string, $dateTime);

            if ( hash_equals($generatedToken, $token) ) {
                return true;
            }
        }

        return false;
    }

    private function makeHash( string $string, string $dateTime ): string {
        $value = $string . $dateTime . 'MD5 TokenService string + datetime';

        $sha1 = sha1($value);

        $first15 = substr($sha1, 5, 20);

        return md5($first15);
    }
}