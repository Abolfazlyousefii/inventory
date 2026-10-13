<?php
namespace Illuminate\Support\Facades {
    class Http {
        public static array $sent = [];
        public static array $reply = ['Value' => 1234];
        public static int $status = 200;
        public static function asForm(): self { return new self(); }
        public function timeout(int $seconds): self { return $this; }
        public function post(string $url, array $data): object {
            self::$sent[] = compact('url','data');
            return new class {
                public function throw(): self { if (Http::$status >= 400) throw new \RuntimeException('HTTP error'); return $this; }
                public function json(): mixed { return Http::$reply; }
            };
        }
    }
    class Log {
        public static array $sent = [];
        public static function info(string $message, array $context): void { self::$sent[] = compact('message', 'context'); }
    }
}
namespace {
    $configs = [
        'sms.default' => 'log',
        'services.melipayamak.username' => 'testuser',
        'services.melipayamak.password' => 'testpass',
        'services.melipayamak.from' => '5000',
        'services.melipayamak.body_id' => '777',
    ];
    function config(string $key, mixed $default = null): mixed { global $configs; return $configs[$key] ?? $default; }
    function data_get(mixed $target, string $key, mixed $default = null): mixed { return is_array($target) ? ($target[$key] ?? $default) : $default; }
    function check(bool $condition, string $name): void { if (!$condition) throw new \RuntimeException('FAILED '.$name); echo 'PASS '.$name."\n"; }
    $root = rtrim((string) ($argv[1] ?? __DIR__), '/').'/';
    foreach (['app/Contracts/OtpSender.php','app/Contracts/SmsDriver.php','app/Services/Sms/Drivers/MelipayamakDriver.php','app/Services/Sms/Drivers/LogDriver.php','app/Services/Sms/SmsManager.php','app/Services/Sms/OtpSmsSender.php'] as $file) {
        require_once $root.$file;
    }
    $manager = new \App\Services\Sms\SmsManager(new \App\Services\Sms\Drivers\MelipayamakDriver(), new \App\Services\Sms\Drivers\LogDriver());
    $adapter = new \App\Services\Sms\OtpSmsSender($manager);
    $adapter->send('09120000000', '987654');
    check(count(\Illuminate\Support\Facades\Log::$sent) === 1, 'log driver through OTP adapter');
    check(str_contains(json_encode(\Illuminate\Support\Facades\Log::$sent, JSON_UNESCAPED_UNICODE), '987654'), 'log mode retains OTP preview');
    $configs['sms.default'] = 'melipayamak';
    $adapter->send('09120000000', '654321');
    $record = \Illuminate\Support\Facades\Http::$sent[0];
    check(str_ends_with($record['url'], 'BaseServiceNumber'), 'pattern endpoint');
    check($record['data']['text'] === '654321' && $record['data']['bodyId'] === '777', 'pattern code and id passed');
    $configs['services.melipayamak.body_id'] = '';
    $adapter->send('09120000000', '111111');
    $record = \Illuminate\Support\Facades\Http::$sent[1];
    check(str_ends_with($record['url'], '/SendSMS') && $record['data']['from'] === '5000', 'normal SMS fallback');
    check($record['data']['text'] === 'کد ورود شما: 111111', 'normal OTP message preserved');
    $configs['services.melipayamak.body_id'] = '777';
    \Illuminate\Support\Facades\Http::$reply = ['Value' => -1];
    try { $adapter->send('09120000000', '111111'); throw new \RuntimeException('should reject'); }
    catch (\RuntimeException $e) { check($e->getMessage() === 'Melipayamak rejected the SMS request.', 'failed provider response throws'); }
    try { $manager->driver('none'); throw new \RuntimeException('should reject unknown driver'); }
    catch (\InvalidArgumentException $e) { check(true, 'unknown driver rejected'); }
    $configs['services.melipayamak.password'] = '';
    $count = count(\Illuminate\Support\Facades\Http::$sent);
    try { $adapter->send('09120000000', '111111'); throw new \RuntimeException('should reject'); }
    catch (\RuntimeException $e) { check($e->getMessage() === 'Melipayamak SMS configuration is incomplete.' && count(\Illuminate\Support\Facades\Http::$sent) === $count, 'missing credentials reject without HTTP'); }
    $configs['services.melipayamak.password'] = 'testpass';
    $configs['services.melipayamak.body_id'] = '';
    $configs['services.melipayamak.from'] = '';
    try { $adapter->send('09120000000', '111111'); throw new \RuntimeException('should reject'); }
    catch (\RuntimeException $e) { check($e->getMessage() === 'Melipayamak sender number is not configured.', 'missing sender rejected'); }
    $configs['services.melipayamak.from'] = '5000';
    $configs['services.melipayamak.body_id'] = '777';
    \Illuminate\Support\Facades\Http::$status = 503;
    try { $adapter->send('09120000000', '111111'); throw new \RuntimeException('should reject'); }
    catch (\RuntimeException $e) { check($e->getMessage() === 'HTTP error', 'transport failure propagated'); }
    \Illuminate\Support\Facades\Http::$status = 200;
    \Illuminate\Support\Facades\Http::$reply = ['value' => 45678];
    $manager->sendPattern('09120000000','888',['a','b']);
    $record = end(\Illuminate\Support\Facades\Http::$sent);
    check($record['data']['text'] === 'a;b' && $record['data']['bodyId'] === '888', 'multiple pattern arguments and lowercase response');
    check(str_contains(file_get_contents($root.'app/Facades/Sms.php'), 'SmsManager::class'), 'SMS facade points to manager');
    check(str_contains(file_get_contents($root.'app/Console/Commands/TestUserOtpCommand.php'), "config('sms.default'"), 'OTP command checks actual driver');
}
