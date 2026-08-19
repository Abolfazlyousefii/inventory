<?php

namespace App\Contracts;

interface OtpSender
{
    public function send(string $phone, string $code): void;
}
