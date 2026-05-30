<?php

namespace App\Mail;

use App\Models\Tenant\Appointment;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AppointmentConfirmationMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Appointment $appointment,
        public string $cabinetName,
        public string $cancelUrl,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), $this->cabinetName),
            subject: "Ihr Termin bei {$this->cabinetName} ist bestätigt",
        );
    }

    public function content(): Content
    {
        $this->appointment->loadMissing(['service', 'practitioner']);

        return new Content(markdown: 'emails.confirmation');
    }
}
