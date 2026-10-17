<?php

namespace App\Services\Sms;

use App\Contracts\SmsDriver;
use App\Services\Sms\Drivers\LogDriver;
use App\Services\Sms\Drivers\MelipayamakDriver;
use InvalidArgumentException;

class SmsManager {
    public function __construct( private readonly MelipayamakDriver $melipayamak, private readonly LogDriver $log ) {}

    public function driver( ?string $name = null ): SmsDriver {
        return match ( $name ?? config('sms.default', 'log') ) {
            'melipayamak' => $this->melipayamak,
            'log'         => $this->log,
            default       => throw new InvalidArgumentException('Unknown SMS driver.'),
        };
    }

    public function send( string $phone, string $message ): void {
        $this->driver()
            ->send($phone, $message);
    }

    public function sendPattern( string $phone, string $pattern, array $parameters = [] ): void {
        $this->driver()
            ->sendPattern($phone, $pattern, $parameters);
    }
}