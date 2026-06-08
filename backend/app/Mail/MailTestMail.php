<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;

/**
 * Throwaway diagnostic email for `php artisan mail:test`.
 * Deliberately NOT ShouldQueue: the smoke test must send synchronously and
 * surface transport errors in the same process. Self-contained HTML, no view.
 */
class MailTestMail extends Mailable
{
    public function __construct(public string $appName) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            subject: e($this->appName).' — Test-E-Mail',
        );
    }

    public function content(): Content
    {
        return new Content(
            htmlString: '<p>Diese Test-E-Mail bestätigt, dass der SMTP-Versand für '
                .e($this->appName).' funktioniert.</p>',
        );
    }
}
