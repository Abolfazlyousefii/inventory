<?php

namespace App\Console\Commands;

use App\Actions\UserAuth\IssueUserOtpAction;
use App\Contracts\OtpSender;
use App\Rules\IranianMobile;
use App\Support\PhoneNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Validator;
use Throwable;

class TestUserOtpCommand extends Command
{
    protected $signature = 'otp:test
        {phone : The existing ERP user mobile number}
        {--send : Send through the configured SMS driver instead of printing the OTP}';

    protected $description = 'Issue an ERP login OTP for a test user (non-production only)';

    public function handle(IssueUserOtpAction $issuer): int
    {
        if (! app()->environment(['local', 'testing', 'staging'])) {
            $this->error('This diagnostic command is restricted to local/testing/staging environments.');
            return self::FAILURE;
        }

        $phone = PhoneNormalizer::normalize((string) $this->argument('phone'));
        if (Validator::make(['phone' => $phone], ['phone' => ['required', new IranianMobile()]])->fails()) {
            $this->error('Invalid Iranian mobile number. Example: 09123456789');
            return self::FAILURE;
        }

        $send = (bool) $this->option('send');
        if ($send && config('sms.default', 'log') === 'log') {
            $this->error('The log driver does not send real SMS. Configure a real driver before using --send.');
            return self::FAILURE;
        }

        // In preview mode we capture the OTP only in this terminal process.
        // No real SMS and no plaintext OTP in storage/logs.
        $preview = new class implements OtpSender {
            public ?string $code = null;

            public function send(string $phone, string $code): void
            {
                $this->code = $code;
            }
        };

        $sender = $send ? app(OtpSender::class) : $preview;

        try {
            if (! $issuer->execute($phone, $sender)) {
                $this->error('No active ERP user found with that phone number.');
                return self::FAILURE;
            }
        } catch (Throwable $exception) {
            report($exception);
            $this->error('OTP generation or delivery failed. Check the database and SMS configuration.');
            return self::FAILURE;
        }

        if ($send) {
            $this->info("OTP sent using the configured SMS driver to {$phone}.");
        } else {
            $this->warn('TEST OTP (terminal only): '.$preview->code);
        }

        $this->line('The OTP was saved as a hash and expires in '.config('jahesh.otp.expires_minutes', 5).' minute(s).');
        return self::SUCCESS;
    }
}
