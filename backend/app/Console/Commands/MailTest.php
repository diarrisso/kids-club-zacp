<?php

namespace App\Console\Commands;

use App\Mail\MailTestMail;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;

class MailTest extends Command
{
    protected $signature = 'mail:test {email : Recipient address for the diagnostic email}';

    protected $description = 'Send a diagnostic email via the configured transport to verify SMTP delivery.';

    public function handle(): int
    {
        $email = (string) $this->argument('email');

        if (Validator::make(['email' => $email], ['email' => 'required|email'])->fails()) {
            $this->error("Invalid email address: {$email}");

            return self::FAILURE;
        }

        try {
            // ->send() (not ->queue()) forces synchronous delivery so any
            // transport error surfaces here instead of being swallowed by a queue.
            Mail::to($email)->send(new MailTestMail(config('app.name')));
        } catch (\Throwable $e) {
            $this->error("Failed to send test mail to {$email}: {$e->getMessage()}");

            return self::FAILURE;
        }

        $this->info("Test mail sent to {$email} via mailer '".config('mail.default')."'.");

        return self::SUCCESS;
    }
}
