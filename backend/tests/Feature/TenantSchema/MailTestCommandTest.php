<?php

use App\Mail\MailTestMail;
use Illuminate\Support\Facades\Mail;

it('sends a test mail to the given address and exits successfully', function () {
    Mail::fake();

    $this->artisan('mail:test', ['email' => 'inbox@example.de'])
        ->expectsOutputToContain('inbox@example.de')
        ->assertExitCode(0);

    Mail::assertSent(MailTestMail::class, fn ($m) => $m->hasTo('inbox@example.de'));
});

it('fails with a non-zero exit code on an invalid email', function () {
    Mail::fake();

    $this->artisan('mail:test', ['email' => 'not-an-email'])
        ->assertExitCode(1);

    Mail::assertNothingSent();
});
