<?php

namespace App\Contracts;

interface SmsDriver
{
        public function send(string $phone, string $message): void;

    public function sendPattern(string $phone, string $pattern, array $parameters = []): void;
}