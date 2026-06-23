<?php

namespace App\Mail;

use App\Models\Tenant\Appointment;
use Carbon\CarbonImmutable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Address;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

class AppointmentRescheduledMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Appointment $appointment,        // state AFTER reschedule (new values)
        public string $cabinetName,
        public string $cancelUrl,
        public CarbonImmutable $oldStart,        // old clinicStartsAt() (Berlin), captured before reschedule
        public string $oldPractitionerName,      // old practitioner full name, captured before
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), $this->cabinetName),
            subject: "Ihr Termin bei {$this->cabinetName} wurde verschoben",
        );
    }

    public function content(): Content
    {
        $this->appointment->loadMissing(['service', 'practitioner']);

        return new Content(markdown: 'emails.rescheduled');
    }
}
