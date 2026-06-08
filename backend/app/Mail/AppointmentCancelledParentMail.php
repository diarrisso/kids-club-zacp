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

/**
 * Parent-facing confirmation that their appointment was cancelled. Sent on every
 * cancellation path (parent storno, widget, staff). Distinct from the internal
 * AppointmentCancelledMail (cabinet alert); exposes no internal fields.
 */
class AppointmentCancelledParentMail extends Mailable implements ShouldQueue
{
    use Queueable, SerializesModels;

    public function __construct(
        public Appointment $appointment,
        public string $cabinetName,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(
            from: new Address(config('mail.from.address'), $this->cabinetName),
            subject: "Ihr Termin wurde storniert — {$this->cabinetName}",
        );
    }

    public function content(): Content
    {
        $this->appointment->loadMissing(['service', 'practitioner']);

        return new Content(markdown: 'emails.cancelled-parent');
    }
}
