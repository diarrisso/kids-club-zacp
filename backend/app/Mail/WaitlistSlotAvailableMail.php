<?php

namespace App\Mail;

use App\Models\WaitlistEntry;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class WaitlistSlotAvailableMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public WaitlistEntry $entry,
        public string $cabinetName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), $this->cabinetName),
            subject: "Ein Termin ist verfügbar — {$this->cabinetName}",
        );
    }

    public function content(): Content
    {
        $this->entry->loadMissing('service');

        return new Content(markdown: 'emails.waitlist-slot-available');
    }
}
