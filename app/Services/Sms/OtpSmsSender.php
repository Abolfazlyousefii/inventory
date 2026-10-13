<?php
  
  namespace App\Services\Sms;
  
  use App\Contracts\OtpSender;
  
  class OtpSmsSender implements OtpSender
  {
          public function __construct(private readonly SmsManager $sms) {}
  
      public function send(string $phone, string $code): void
      {
                  $pattern = (string) config('services.melipayamak.body_id', '');
          
                  if ($pattern !== '') {
                          $this->sms->sendPattern($phone, $pattern, [$code]);
                          return;
          }
  
          $this->sms->send($phone, "کد ورود شما: {$code}");
      }
  }